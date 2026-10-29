<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The five emails the Clearance Signatory escalation workflow sends, seeded
 * as ordinary, editable Email & Notification templates (same convention as
 * every other fixed-name template). Looked up by `template_name` in
 * `ClearanceSignatoryEscalationService`; an admin may reword or deactivate
 * them — a deactivated one is skipped (logged), the in-app notification is
 * still created.
 */
return new class extends Migration
{
    private function row(string $label, string $value): string
    {
        return '<tr><td style="padding:4px 8px;color:#6b7280;width:190px;">'.$label.'</td><td style="padding:4px 8px;font-weight:bold;">'.$value.'</td></tr>';
    }

    private function table(array $rows): string
    {
        return '<table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0;"><tbody>'.implode('', $rows).'</tbody></table>';
    }

    private function templates(): array
    {
        $footer = '<p>{{offboarding_link}}</p><p>Thank you.</p><p>Regards,</p><p>CIM Offboarding Assistant</p>';
        $common = [
            $this->row('Employee', '{{employee_name}}'),
            $this->row('Employee No.', '{{employee_number}}'),
            $this->row('Checklist', '{{checklist_name}}'),
        ];

        return [
            'Clearance Signatory Escalation — Assigned to You' => [
                'subject' => 'Action required: you are now the Clearance Signatory for {{checklist_name}} ({{employee_name}})',
                'body' => '<p>Hello {{approver_name}},</p>'
                    .'<p>You have been assigned as the new <strong>Clearance Signatory</strong> for the checklist below. Responsibility has been transferred to you because the previous Clearance Signatory did not complete the checklist within the configured period.</p>'
                    .$this->table([...$common,
                        $this->row('Previous Clearance Signatory', '{{previous_signatory_name}}'),
                        $this->row('Reason', '{{escalation_reason}}'),
                        $this->row('Originally Assigned', '{{original_assigned_at}}'),
                        $this->row('Assigned to You', '{{new_assigned_at}}'),
                        $this->row('Complete By', '{{signatory_deadline}}'),
                        $this->row('Checklist Status', '{{checklist_status}}'),
                    ])
                    .'<p><strong>Remaining checklist actions:</strong></p>{{pending_items}}'
                    .'<p>Please sign in to review and complete this checklist.</p>'.$footer,
            ],
            'Clearance Signatory Responsibility Transferred' => [
                'subject' => 'Clearance Signatory responsibility transferred: {{checklist_name}} ({{employee_name}})',
                'body' => '<p>Hello {{approver_name}},</p>'
                    .'<p>The checklist below was not completed within the configured period, so its Clearance Signatory responsibility has been transferred to <strong>{{new_signatory_name}}</strong>. You no longer need to sign this checklist. Any checklist tasks individually assigned to you remain yours.</p>'
                    .$this->table([...$common,
                        $this->row('Your Deadline', '{{original_deadline}}'),
                        $this->row('Transferred On', '{{escalated_at}}'),
                    ]).$footer,
            ],
            'Clearance Signatory Escalation — HR/Admin Notice' => [
                'subject' => 'Clearance Signatory escalated: {{checklist_name}} ({{employee_name}})',
                'body' => '<p>Hello {{approver_name}},</p>'
                    .'<p>The primary Clearance Signatory did not complete the checklist below within the configured period. It has been automatically transferred to the secondary Clearance Signatory. No action is required from you at this time.</p>'
                    .$this->table([...$common,
                        $this->row('Primary Clearance Signatory', '{{primary_signatory_name}}'),
                        $this->row('Primary Assigned / Deadline', '{{original_assigned_at}} / {{original_deadline}}'),
                        $this->row('Secondary Clearance Signatory', '{{secondary_signatory_name}}'),
                        $this->row('Secondary Deadline', '{{secondary_deadline}}'),
                        $this->row('Escalated On', '{{escalated_at}}'),
                    ]).$footer,
            ],
            'Clearance Signatory Overdue — HR/Admin Action Required' => [
                'subject' => 'HR/Admin action required: assign a new Clearance Signatory for {{checklist_name}} ({{employee_name}})',
                'body' => '<p>Hello {{approver_name}},</p>'
                    .'<p>The secondary Clearance Signatory also did not complete the checklist below within the configured period. Automatic reassignment has stopped. <strong>Please assign a new Clearance Signatory.</strong></p>'
                    .$this->table([...$common,
                        $this->row('Primary Clearance Signatory', '{{primary_signatory_name}}'),
                        $this->row('Primary Assigned / Deadline', '{{original_assigned_at}} / {{original_deadline}}'),
                        $this->row('Secondary Clearance Signatory', '{{secondary_signatory_name}}'),
                        $this->row('Secondary Assigned / Deadline', '{{secondary_assigned_at}} / {{secondary_deadline}}'),
                        $this->row('Escalated On', '{{escalated_at}}'),
                        $this->row('Checklist Status', '{{checklist_status}}'),
                        $this->row('Reason', '{{escalation_reason}}'),
                    ])
                    .'<p>Open the Approvals page and use <strong>Assign New Clearance Signatory</strong> in the Clearance Signatory Escalations panel.</p>'.$footer,
            ],
            'Clearance Signatory Assigned by HR/Admin' => [
                'subject' => 'You have been assigned as Clearance Signatory: {{checklist_name}} ({{employee_name}})',
                'body' => '<p>Hello {{approver_name}},</p>'
                    .'<p>HR/Admin has assigned you as the <strong>Clearance Signatory</strong> for the checklist below.</p>'
                    .$this->table([...$common,
                        $this->row('Previous Clearance Signatory', '{{previous_signatory_name}}'),
                        $this->row('Assigned By', '{{reassigned_by}}'),
                        $this->row('Reason', '{{escalation_reason}}'),
                        $this->row('Assigned to You', '{{new_assigned_at}}'),
                        $this->row('Complete By', '{{signatory_deadline}}'),
                        $this->row('Checklist Status', '{{checklist_status}}'),
                    ])
                    .'<p><strong>Remaining checklist actions:</strong></p>{{pending_items}}'
                    .'<p>Please sign in to review and complete this checklist.</p>'.$footer,
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->templates() as $name => $template) {
            if (DB::table('email_templates')->where('template_name', $name)->exists()) {
                continue;
            }

            DB::table('email_templates')->insert([
                'template_name' => $name,
                'subject' => $template['subject'],
                'html_content' => $template['body'],
                'is_active' => true,
                'is_default_announcement' => false,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')->whereIn('template_name', array_keys($this->templates()))->delete();
    }
};
