<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Notifications\Infrastructure\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends a template to a customer (email; SMS/WhatsApp logged as skipped until a provider is
 * chosen) or to the operations mailbox. Each message has a dedupe key so at-least-once events
 * never produce duplicate messages. Raw addresses are never stored in logs (hash only).
 */
final class Notifier
{
    public function __construct(private readonly UserDirectory $users) {}

    /** @param array<string, string> $params */
    public function toCustomer(string $userId, string $template, array $params, string $dedupeKey, string $relatedType, string $relatedId, ?string $actionPath = null): void
    {
        $contact = $this->users->contact($userId);
        if ($contact === null) {
            return;
        }
        $locale = $contact['locale'];
        $params += ['name' => $contact['name']];

        if ($contact['email'] === null) {
            $this->log($userId, $contact['phone'] !== null ? 'sms' : 'email', $template, $locale, (string) $contact['phone'], $dedupeKey, $relatedType, $relatedId,
                $contact['phone'] !== null ? 'skipped_no_provider' : 'skipped_no_contact');

            return;
        }

        if (! $this->claim($userId, 'email', $template, $locale, $contact['email'], $dedupeKey, $relatedType, $relatedId)) {
            return; // already sent
        }

        try {
            $url = $actionPath !== null ? rtrim((string) config('gulfcart.frontend_url'), '/').($locale === 'ar' ? '/ar' : '').$actionPath : null;
            Mail::to($contact['email'])->send($this->mail($template, $params, $locale, $url));
        } catch (Throwable $e) {
            NotificationLog::query()->where('dedupe_key', $dedupeKey)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::error('notifications.send_failed', ['template' => $template, 'error' => $e->getMessage()]);
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
