<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ChecklistItemApproverAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{checklistTitle: string, itemTitle: string, dueAt: ?string}>  $assignedItems
     * @param  array{username: string, password: string}|null  $credentials  Only set when a new account was just created for this approver.
     */
    public function __construct(
        public string $approverName,
        public string $offboardeeName,
        public string $offboardeeEmployeeCode,
        public array $assignedItems,
        public string $approvalUrl,
        public ?array $credentials = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Offboarding Checklist Assigned to You',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.checklist-item-approver-assigned',
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
