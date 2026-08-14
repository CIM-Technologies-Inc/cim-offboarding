<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChecklistReadyForApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{title: string, checkedByName: ?string, checkedAt: ?string, remark: ?string}>  $items
     */
    public function __construct(
        public string $departmentHeadName,
        public string $offboardeeName,
        public string $offboardeeEmployeeCode,
        public string $checklistTitle,
        public array $items,
        public string $approvalUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Checklist Ready for Department Head Approval',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checklist-ready-for-approval',
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
