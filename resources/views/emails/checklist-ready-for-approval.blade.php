<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: Arial, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#145a3a; padding:20px 32px;">
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">Checklist Ready for Department Head Approval</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;">
                            <p style="margin:0 0 16px;">Hello {{ $departmentHeadName }},</p>
                            <p style="margin:0 0 20px;">
                                All required checklist items for the following employee offboarding request have been checked and are now ready for your final review and approval.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px; font-size:14px;">
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280; width:140px;">Employee</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $offboardeeName }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Employee No.</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $offboardeeEmployeeCode }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Checklist(s)</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ collect($checklists)->pluck('title')->implode(', ') }}</td>
                                </tr>
                            </table>

                            <p style="margin:0 0 4px; font-weight:bold;">Current Status:</p>
                            <p style="margin:0 0 20px; color:#145a3a; font-weight:bold;">Ready for Department Head Approval</p>

                            @foreach ($checklists as $checklist)
                                <p style="margin:0 0 8px; font-weight:bold;">{{ $checklist['title'] }}:</p>
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:16px; font-size:14px;">
                                    <tr>
                                        <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Item</td>
                                        <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Status</td>
                                        <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Checked By</td>
                                        <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Date/Time</td>
                                        <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Remarks</td>
                                    </tr>
                                    @forelse ($checklist['items'] as $item)
                                        <tr>
                                            <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $loop->iteration }}. {{ $item['title'] }}</td>
                                            <td style="padding:8px 10px; border:1px solid #e5e7eb;">Checked</td>
                                            <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $item['checkedByName'] ?? '—' }}</td>
                                            <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $item['checkedAt'] ?? '—' }}</td>
                                            <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $item['remark'] ?? '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" style="padding:8px 10px; border:1px solid #e5e7eb; color:#6b7280;">No individual checklist items.</td>
                                        </tr>
                                    @endforelse
                                </table>
                            @endforeach

                            <p style="margin:0 0 24px;">All checklist items have been checked.</p>

                            <p style="margin:0 0 24px;">
                                Review the checklist(s) in the system, or approve them all directly from this email.
                            </p>

                            <p style="margin:0;">
                                <a href="{{ $approvalUrl }}" style="display:inline-block; background-color:#ffffff; color:#145a3a; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold; border:2px solid #145a3a; margin-right:12px;">
                                    Review Checklist
                                </a>
                                <a href="{{ $approveUrl }}" style="display:inline-block; background-color:#145a3a; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold;">
                                    Approve
                                </a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
