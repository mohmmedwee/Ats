<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Contracts\SmsSender;
use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Notifications\Infrastructure\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a template to a customer (email, WhatsApp, SMS) or to the operations mailbox. Each message has a dedupe key so at-least-once events
 * never produce duplicate messages. Raw addresses are never stored in logs (hash only).
 */
final class Notifier
{
    public function __construct(private readonly UserDirectory $users, private readonly WhatsAppSender $whatsapp, private readonly SmsSender $sms) {}

    /**
     * Email when the customer has one; plus WhatsApp (when configured and the template is mapped) or,
     * for customers without email, a short SMS, so phone-only accounts still hear about their orders.
     *
     * @param  array<string, string>  $params
     */
    public function toCustomer(string $userId, string $template, array $params, string $dedupeKey, string $relatedType, string $relatedId, ?string $actionPath = null): void
    {
        $contact = $this->users->contact($userId);
        if ($contact === null) {
            return;
        }
        $locale = $contact['locale'];
        $params += ['name' => $contact['name'] !== '' ? $contact['name'] : (string) __('notifications.customer', [], $locale)];
        $url = $actionPath !== null ? rtrim((string) config('gulfcart.frontend_url'), '/').($locale === 'ar' ? '/ar' : '').$actionPath : null;

        if ($contact['email'] !== null && $this->claim($userId, 'email', $template, $locale, $contact['email'], $dedupeKey, $relatedType, $relatedId)) {
            try {
                Mail::to($contact['email'])->send($this->mail($template, $params, $locale, $url));
            } catch (Throwable $e) {
                NotificationLog::query()->where('dedupe_key', $dedupeKey)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                Log::error('notifications.send_failed', ['template' => $template, 'error' => $e->getMessage()]);
            }
        }

        $phone = $contact['phone'];
        if ($phone === null) {
            if ($contact['email'] === null) {
                $this->log($userId, 'email', $template, $locale, $userId, $dedupeKey, $relatedType, $relatedId, 'skipped_no_contact');
            }

            return;
        }

        $wa = $this->whatsapp->enabled() ? $this->whatsapp->templateFor($template) : null;
        if ($wa !== null) {
            if ($this->claim($userId, 'whatsapp', $template, $locale, $phone, $dedupeKey.':wa', $relatedType, $relatedId)) {
                try {
                    $this->whatsapp->send($phone, $wa['name'], $locale, array_map(fn ($k) => (string) ($params[$k] ?? ''), $wa['params']), $actionPath !== null ? ltrim(($locale === 'ar' ? '/ar' : '').$actionPath, '/') : null);
                } catch (Throwable $e) {
                    NotificationLog::query()->where('dedupe_key', $dedupeKey.':wa')->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
                    Log::warning('notifications.whatsapp_failed', ['template' => $template, 'error' => $e->getMessage()]);
                }
            }

            return;
        }

        $sms = __("notifications.{$template}.sms", $params + ['url' => (string) $url], $locale);
        if ($contact['email'] === null && is_string($sms) && $sms !== "notifications.{$template}.sms"
            && $this->claim($userId, 'sms', $template, $locale, $phone, $dedupeKey.':sms', $relatedType, $relatedId)) {
            try {
                $this->sms->send($phone, $sms);
            } catch (Throwable $e) {
                NotificationLog::query()->where('dedupe_key', $dedupeKey.':sms')->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            }
        }
    }

    /** @param array<string, string> $params */
    public function toOperations(string $template, array $params, string $dedupeKey, string $relatedType, string $relatedId): void
    {
        $email = (string) config('gulfcart.notifications.operations_email');
        if ($email === '' || ! $this->claim(null, 'email', $template, 'en', $email, $dedupeKey, $relatedType, $relatedId)) {
            return;
        }
        Mail::to($email)->send($this->mail($template, $params, 'en', null));
    }

    /** @param array<string, string> $params */
    private function mail(string $template, array $params, string $locale, ?string $url): TemplateMail
    {
        $t = fn (string $key) => (string) __("notifications.{$template}.{$key}", $params, $locale);
        $lines = trans("notifications.{$template}.lines", $params, $locale);

        return new TemplateMail(
            $t('subject'),
            (string) __('notifications.greeting', $params, $locale),
            is_array($lines) ? array_values(array_map('strval', $lines)) : [],
            $url !== null ? $t('action') : null,
            $url,
            $locale,
        );
    }

    /**
     * Reserves the dedupe key. Uses INSERT … ON CONFLICT DO NOTHING: catching a unique violation
     * instead would abort the surrounding PostgreSQL transaction (the outbox handler's savepoint).
     */
    private function claim(?string $userId, string $channel, string $template, string $locale, string $recipient, string $dedupeKey, string $relatedType, string $relatedId): bool
    {
        return NotificationLog::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'channel' => $channel, 'template' => $template,
            'locale' => $locale, 'recipient_hash' => hash('sha256', mb_strtolower($recipient)), 'related_type' => $relatedType,
            'related_id' => $relatedId, 'dedupe_key' => $dedupeKey, 'status' => 'sent', 'created_at' => now(),
        ]) > 0;
    }

    private function log(?string $userId, string $channel, string $template, string $locale, string $recipient, string $dedupeKey, string $relatedType, string $relatedId, string $status): void
    {
        NotificationLog::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'created_at' => now(),
            'user_id' => $userId, 'channel' => $channel, 'template' => $template, 'locale' => $locale,
            'recipient_hash' => hash('sha256', mb_strtolower($recipient)), 'related_type' => $relatedType,
            'related_id' => $relatedId, 'dedupe_key' => $dedupeKey, 'status' => $status,
        ]);
    }
}
