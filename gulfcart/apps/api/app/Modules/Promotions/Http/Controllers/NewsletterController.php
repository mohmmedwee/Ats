<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Modules\Countries\Domain\CountryData;
use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Newsletter sign-up with an optional welcome code (NEWSLETTER_WELCOME_CODE: an active coupon you create
 * in Admin → Coupons, e.g. WELCOME10, one use per customer). Consent is explicit and one-click to undo.
 */
final class NewsletterController
{
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255'], 'source' => ['nullable', 'string', 'max:30']]);
        /** @var CountryData|null $country */
        $country = $request->attributes->get('country');
        $existing = DB::table('newsletter_subscribers')->where('email', $data['email'])->first();
        if ($existing === null) {
            DB::table('newsletter_subscribers')->insert([
                'id' => (string) Str::uuid7(), 'email' => mb_strtolower($data['email']), 'locale' => app()->getLocale() === 'ar' ? 'ar' : 'en',
                'country_code' => $country?->code, 'source' => $data['source'] ?? 'footer', 'unsubscribe_token' => Str::random(48), 'subscribed_at' => now(),
            ]);
        } elseif ($existing->unsubscribed_at !== null) {
            DB::table('newsletter_subscribers')->where('id', $existing->id)->update(['unsubscribed_at' => null, 'subscribed_at' => now()]);
        }

        return ApiResponse::success(['subscribed' => true, 'welcome_code' => $existing === null ? $this->welcomeCode() : null], $existing === null ? 201 : 200);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);
        $done = DB::table('newsletter_subscribers')->where('unsubscribe_token', $data['token'])->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);

        return ApiResponse::success(['unsubscribed' => true, 'changed' => $done > 0]);
    }

    /** Staff: counts, and a CSV of active subscribers for the email tool. */
    public function admin(Request $request): JsonResponse|StreamedResponse
    {
        if ($request->query('format') === 'csv') {
            return response()->streamDownload(function () {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['email', 'locale', 'country', 'source', 'subscribed_at', 'unsubscribe_token']);
                DB::table('newsletter_subscribers')->whereNull('unsubscribed_at')->orderBy('subscribed_at')->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $r) {
                        fputcsv($out, [$r->email, $r->locale, $r->country_code, $r->source, $r->subscribed_at, $r->unsubscribe_token]);
                    }
                });
                fclose($out);
            }, 'newsletter-subscribers.csv', ['Content-Type' => 'text/csv']);
        }

        return ApiResponse::success([
            'active' => DB::table('newsletter_subscribers')->whereNull('unsubscribed_at')->count(),
            'unsubscribed' => DB::table('newsletter_subscribers')->whereNotNull('unsubscribed_at')->count(),
            'by_country' => DB::table('newsletter_subscribers')->whereNull('unsubscribed_at')->groupBy('country_code')->selectRaw('country_code, count(*) as n')->pluck('n', 'country_code'),
            'welcome_code' => $this->welcomeCode(),
        ]);
    }

    private function welcomeCode(): ?string
    {
        $code = strtoupper(trim((string) config('gulfcart.newsletter.welcome_code', '')));

        return $code !== '' && Coupon::query()->where('code', $code)->where('is_active', true)->whereNull('user_id')->exists() ? $code : null;
    }
}
