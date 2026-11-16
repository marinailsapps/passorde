<?php

namespace App\Mail;

use App\Support\Profile\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * ops:daily-digest: what needs the admin's attention (plain text, French).
 */
class OpsDigestMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{title: string, lines: list<string>}>  $sections
     */
    public function __construct(public array $sections) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: trans_choice(':brand: :count points to check', count($this->sections), ['brand' => Brand::shortName()]));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ops-digest', with: ['appUrl' => config('app.url'), 'brand' => Brand::name()]);
    }
}
