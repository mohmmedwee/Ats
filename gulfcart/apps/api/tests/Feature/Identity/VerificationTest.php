<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\LogSmsSender;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class VerificationTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $sms = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SmsSender::class, new class($this->sms) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $e164Phone, string $message): void
            {
                $this->sent[] = [$e164Phone, $message];
            }
        });
    }

    private function codeFromSms(): string
    {
        preg_match('/\b(\d{6})\b/', end($this->sms)[1], $m);

        return $m[1];
    }

    public function test_phone_is_verified_with_a_one_time_code(): void
    {
        $user = $this->customer();
        $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];

        $this->postJson('/api/v1/auth/otp/send', ['phone' => '+971 50 123 4567'], $auth)->assertOk();
        $this->assertSame('+971501234567', $this->sms[0][0]);

        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'code' => '000000'], $auth), 422, 'OTP_INVALID');
        $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'code' => $this->codeFromSms()], $auth)->assertOk()
            ->assertJsonPath('data.phone', '+971501234567')->assertJsonPath('data.phone_verified', true);

        // Codes are single-use.
        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+971501234567', 'code' => $this->codeFromSms()], $auth), 422, 'OTP_INVALID');
    }

    public function test_codes_expire_and_lock_after_too_many_attempts(): void
    {
        $user = $this->customer();
        $auth = ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '+966551234567'], $auth)->assertOk();
        $code = $this->codeFromSms();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966551234567', 'code' => '111111'], $auth);
        }
        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966551234567', 'code' => $code], $auth), 422, 'OTP_INVALID');

        $this->travel(1)->hours();
        $this->postJson('/api/v1/auth/otp/send', ['phone' => '+966551234567'], $auth)->assertOk();
        $this->travel(11)->minutes();
        $this->assertErrorCode($this->postJson('/api/v1/auth/verify-otp', ['phone' => '+966551234567', 'code' => $this->codeFromSms()], $auth), 422, 'OTP_INVALID');
    }

    public function test_otp_rejects_foreign_numbers_and_numbers_owned_by_others(): void
    {
        $this->customer(['phone' => '+971509999999']);
        $auth = ['Authorization' => 'Bearer '.$this->customer()->createToken('t')->plainTextToken];

        $this->assertErrorCode($this->postJson('/api/v1/auth/otp/send', ['phone' => '+15551234567'], $auth), 422, 'VALIDATION_FAILED');
        $this->assertErrorCode($this->postJson('/api/v1/auth/otp/send', ['phone' => '+971509999999'], $auth), 422, 'VALIDATION_FAILED');
        $this->assertSame([], $this->sms);
    }

    public function test_otp_sending_is_rate_limited(): void
    {
        $auth = ['Authorization' => 'Bearer '.$this->customer()->createToken('t')->plainTextToken];
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/otp/send', ['phone' => '+971501112233'], $auth)->assertOk();
        }
        $this->assertErrorCode($this->postJson('/api/v1/auth/otp/send', ['phone' => '+971501112233'], $auth), 429, 'RATE_LIMITED');
    }

    public function test_default_sms_sender_is_bound_and_refused_in_production(): void
    {
        $this->app->forgetInstance(SmsSender::class);
        $this->app->offsetUnset(SmsSender::class);
        (new IdentityServiceProvider($this->app))->register();
        $this->assertInstanceOf(LogSmsSender::class, $this->app->make(SmsSender::class));

        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        $this->app->make(SmsSender::class)->send('+971500000000', 'x');
    }

    public function test_registration_sends_a_verification_email_with_a_working_signed_link(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sara', 'email' => 'sara@example.com', 'password' => 'Secret-pass-1', 'password_confirmation' => 'Secret-pass-1',
        ])->assertCreated()->assertJsonPath('data.email_verified', false);

        $user = User::query()->where('email', 'sara@example.com')->firstOrFail();
        $link = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $n) use ($user, &$link) {
            $url = $n->toMail($user)->actionUrl;
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $link = $q['link'] ?? null;

            return str_starts_with($url, 'http://spa.gulfcart.test/verify-email?link=');
        });

        $this->assertErrorCode($this->getJson(str_replace('signature=', 'signature=tampered', (string) $link)), 403, 'INVALID_SIGNATURE');
        $this->getJson((string) $link)->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotNull($user->refresh()->email_verified_at);
    }
}
