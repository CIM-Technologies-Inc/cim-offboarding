<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Final Approval Request email — reuses the same generic subject/body
 * wrapper view every other announcement email in this app uses
 * (`emails.checklist-signatory-announcement`), but unlike those, always
 * carries the Clearance Form as a real attachment: the PDF always, plus a
 * rasterized PNG "screenshot" of it when one could be generated (see
 * `ClearanceFormController::screenshotPngFromBytes()` — requires Imagick +
 * Ghostscript; its absence degrades to PDF-only rather than failing the
 * whole send).
 */
class FinalApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $emailSubject,
        public string $emailBody,
        public string $pdfBytes,
        public string $offboardeeName,
        public ?string $pngBytes = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checklist-signatory-announcement',
            with: [
                'emailSubject' => $this->emailSubject,
                'emailBody' => $this->emailBody,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => $this->pdfBytes, 'Clearance Form - ' . $this->offboardeeName . '.pdf')
                ->withMime('application/pdf'),
        ];

        if ($this->pngBytes !== null) {
            $attachments[] = Attachment::fromData(fn () => $this->pngBytes, 'Clearance Form - ' . $this->offboardeeName . '.png')
                ->withMime('image/png');
        }

        return $attachments;
    }
}
