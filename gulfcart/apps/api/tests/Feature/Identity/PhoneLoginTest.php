<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\HttpSmsSender;
use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PhoneLoginTest extends TestCase
{
    /** @var list<array{0: string, 1: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(SmsSender::class, new class($this->sent) implements SmsSender
        {
            /** @param list<array{0: string, 1: string}> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $e164Phone, string $message): void
            {
                $this->sent[] = [$e164Phone, $message];
            }
        });
    }

    private function code(): string
    {
        preg_match('/\d{6}/', end($this->sent)[1], $m);

        return $m[0];
    }

    public function test_new_number_gets_an_account_on_first_code_and_signs_in(): void
    {
        $this->postJson('/api/v1/auth/phone/start', ['country' => 'AE', 'phone' => '050 123 4567'])->assertOk()->assertJsonPath('data.sent', true);
        $this->assertSame('+971501234567', $this->sent[0][0]);

        $res = $this->postJson('/api/v1/auth/phone/verify', ['country' => 'AE', 'phone' => '0501234567', 'code' => $this->code(), 'device_name' => 'test'])
            ->assertOk()->assertJsonPath('data.new_user', true)->assertJsonPath('data.profile_incomplete', true);
        $user = User::query()->where('phone', '+971501234567')->sole();
        $this->assertTrue($user->hasRole('customer'));
        $this->assertNotNull($user->phone_verified_at);

        $this->withToken($res->json('data.token'))->patchJson('/api/v1/auth/me', ['name' => 'Sara', 'email' => 'Sara@Example.com'])
            ->assertOk()->assertJsonPath('data.name', 'Sara')->assertJsonPath('data.email', 'sara@example.com');
    }

    public function test_existing_customer_signs_in_and_codes_are_single_use_and_attempt_limited(): void
    {
        $user = $this->customer();
        $user->forceFill(['phone' => '+966551234567'])->save();

        $this->postJson('/api/v1/auth/phone/start', ['country' => 'SA', 'phone' => '+966 55 123 4567'])->assertOk();
        $code = $this->code();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->postJson('/api/v1/auth/phone/verify', ['country' => 'SA', 'phone' => '0551234567', 'code' => $wrong])->assertUnprocessable()->assertJsonPath('error.code', 'OTP_INVALID');
        $this->postJson('/api/v1/auth/phone/verify', ['country' => 'SA', 'phone' => '0551234567', 'code' => $code, 'device_name' => 't'])
            ->assertOk()->assertJsonPath('data.new_user', false)->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/v1/auth/phone/verify', ['country' => 'SA', 'phone' => '0551234567', 'code' => $code])->assertUnprocessable();

        $this->postJson('/api/v1/auth/phone/start', ['country' => 'SA', 'phone' => '0551234567'])->assertOk();
        $code = $this->code();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/phone/verify', ['country' => 'SA', 'phone' => '0551234567', 'code' => $code === '000000' ? '111111' : '000000']);
        }
        $this->postJson('/api/v1/auth/phone/verify', ['country' => 'SA', 'phone' => '0551234567', 'code' => $code])->assertUnprocessable();
    }

    public function test_staff_numbers_cannot_sign_in_by_sms_and_invalid_numbers_are_rejected(): void
    {
        $staff = $this->staff(['admin']);
        $staff->forceFill(['phone' => '+971509999999'])->save();
        $this->postJson('/api/v1/auth/phone/start', ['country' => 'AE', 'phone' => '0509999999'])->assertOk();
        $this->assertSame([], $this->sent, 'no code is sent to staff numbers');

        $this->postJson('/api/v1/auth/phone/start', ['country' => 'AE', 'phone' => '0412345678'])->assertUnprocessable();
    }

    public function test_twilio_driver_posts_the_message(): void
    {
        config(['gulfcart.sms.provider' => 'twilio', 'gulfcart.sms.twilio_sid' => 'AC1', 'gulfcart.sms.twilio_token' => 't', 'gulfcart.sms.sender' => 'GulfCart']);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        (new HttpSmsSender('twilio'))->send('+971501234567', 'hi');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'Accounts/AC1/Messages.json') && $r['To'] === '+971501234567' && $r['From'] === 'GulfCart');
    }
}
