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
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">Checklist Approval Confirmed</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;">
                            <p style="margin:0 0 16px;">Hello {{ $creatorName }},</p>
                            <p style="margin:0 0 20px;">
                                This confirms that {{ $approverName }} has completed and approved the following checklist(s) for this employee's offboarding request.
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

                            <p style="margin:0 0 4px; font-weight:bold;">Status:</p>
                            <p style="margin:0 0 20px; color:#145a3a; font-weight:bold;">Approved</p>

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
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
