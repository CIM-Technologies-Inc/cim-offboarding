<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6; font-family: Arial, sans-serif; color:#1f2937;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#145a3a; padding:20px 32px;">
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">Offboarding Checklist Assigned to You</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;">
                            <p style="margin:0 0 16px;">Hello {{ $approverName }},</p>
                            <p style="margin:0 0 20px;">
                                You have been assigned {{ count($assignedItems) > 1 ? 'checklist items that require' : 'a checklist item that requires' }} your review.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px; font-size:14px;">
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280; width:140px;">Employee</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $offboardeeName }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Employee No.</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $offboardeeEmployeeCode }}</td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin-bottom:20px; font-size:14px;">
                                <tr>
                                    <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Checklist</td>
                                    <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Checklist Item</td>
                                    <td style="padding:8px 10px; background-color:#f3f4f6; font-weight:bold; border:1px solid #e5e7eb;">Due Date</td>
                                </tr>
                                @foreach ($assignedItems as $item)
                                    <tr>
                                        <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $item['checklistTitle'] }}</td>
                                        <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $item['itemTitle'] }}</td>
                                        <td style="padding:8px 10px; border:1px solid #e5e7eb;">{{ $item['dueAt'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </table>

                            <p style="margin:0 0 24px;">
                                Please log in to the CIM OffBoarding system to review and complete your assigned checklist.
                            </p>

                            <p style="margin:0 0 24px;">
                                <a href="{{ $approvalUrl }}" style="display:inline-block; background-color:#145a3a; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold;">
                                    Open Checklist
                                </a>
                            </p>

                            @if ($credentials)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fef9c3; border:1px solid #fde68a; border-radius:8px; margin-bottom:8px;">
                                    <tr>
                                        <td style="padding:16px 20px; font-size:14px;">
                                            <p style="margin:0 0 8px; font-weight:bold;">Your Initial Login Credentials</p>
                                            <p style="margin:0;">Username: <strong>{{ $credentials['username'] }}</strong></p>
                                            <p style="margin:0 0 8px;">Temporary Password: <strong>{{ $credentials['password'] }}</strong></p>
                                            <p style="margin:0; color:#92400e;">These are your initial login credentials. Please change your password after logging in.</p>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
