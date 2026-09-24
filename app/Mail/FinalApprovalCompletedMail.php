<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the offboarding request's original creator once the Final
 * Signatory approves via the emailed one-click link — the request has now
 * cleared every stage, this is the definitive "it's done" notice. Mirrors
 * `ChecklistGroupApprovedMail`'s exact shape/purpose (a completion
 * confirmation back to whoever needs to know work finished), just for the
 * one-time Final Approval step instead of a department checklist group.
 *
 * `ShouldQueue` — sent in the background instead of blocking the request;
 * see `ChecklistSignatoryAnnouncementMail`'s own matching comment for why.
 */
class FinalApprovalCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $creatorName,
        public string $offboardeeName,
        public string $offboardeeEmployeeCode,
        public string $finalApproverName,
        public string $finalApproverEmployeeCode,
        public string $approvedAt,
        public ?string $remarks,
        public string $viewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Offboarding Request Fully Approved — ' . $this->offboardeeName . ' (' . $this->offboardeeEmployeeCode . ')',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.final-approval-completed',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
