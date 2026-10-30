<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Mail\Concerns\CapturesEmailLogContext;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Final Pay Checklist Completion notification — reuses the same generic
 * subject/body wrapper view every other announcement email in this app uses
 * (`emails.checklist-signatory-announcement`), but carries the combined
 * Clearance Form + Offboarding Timeline PDF (see
 * `ClearanceFormController::generateCompletionPdfBytes()`) as an attachment,
 * a permanent record of the whole completed process for whoever receives
 * this email. Sibling of `FinalApprovalRequestMail` — same
 * base64-for-queueing reasoning applies (see its own matching comment); no
 * PNG screenshot here, only the PDF was asked for.
 *
 * `ChecklistCompletionService::sendFinalPayCompletionNotification()` falls
 * back to the plain, attachment-less `ChecklistSignatoryAnnouncementMail`
 * instead of this one whenever PDF generation itself fails — this Mailable
 * is only ever constructed once real PDF bytes already exist.
 */
class FinalPayCompletionMail extends Mailable implements ShouldQueue
{
    use CapturesEmailLogContext, Queueable, SerializesModels;

    // Stored base64-encoded, not raw — see FinalApprovalRequestMail's
    // matching comment: ShouldQueue JSON-encodes public properties to
    // persist the job, and raw PDF bytes aren't valid UTF-8.
    public string $pdfBytes;

    public function __construct(
        public string $emailSubject,
        public string $emailBody,
        string $pdfBytes,
        public string $offboardeeName,
    ) {
        $this->captureEmailLogContext($emailSubject, $offboardeeName);
        $this->pdfBytes = base64_encode($pdfBytes);
    }

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
        return [
            Attachment::fromData(fn () => base64_decode($this->pdfBytes), 'Clearance Form and Timeline - '.$this->offboardeeName.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
