<?php

namespace App\Modules\Notifications\Application;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** One bilingual-capable email built from lang/{locale}/notifications.php. */
final class TemplateMail extends Mailable
{
    use Queueable;

    /** @param list<string> $lines */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $greeting,
        public readonly array $lines,
        public readonly ?string $actionText,
        public readonly ?string $actionUrl,
        public readonly string $mailLocale,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'notifications::email', with: [
            'greeting' => $this->greeting, 'lines' => $this->lines, 'actionText' => $this->actionText,
            'actionUrl' => $this->actionUrl, 'dir' => $this->mailLocale === 'ar' ? 'rtl' : 'ltr', 'lang' => $this->mailLocale,
        ]);
    }
}
