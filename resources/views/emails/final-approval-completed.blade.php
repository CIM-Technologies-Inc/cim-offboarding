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
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">Offboarding Request Fully Approved</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;">
                            <p style="margin:0 0 16px;">Hello {{ $creatorName }},</p>
                            <p style="margin:0 0 20px;">
                                The Final Signatory has approved this offboarding request via email. Every stage — checklists, General Signatories, and Final Approval — is now complete.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:16px; font-size:14px;">
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280; width:160px;">Employee</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $offboardeeName }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Employee No.</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $offboardeeEmployeeCode }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Final Approver</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $finalApproverName }} ({{ $finalApproverEmployeeCode }})</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Approved On</td>
                                    <td style="padding:4px 0; font-weight:bold;">{{ $approvedAt }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0; color:#6b7280;">Approval Method</td>
                                    <td style="padding:4px 0; font-weight:bold;">Via Email</td>
                                </tr>
                                @if ($remarks)
                                    <tr>
                                        <td style="padding:4px 0; color:#6b7280; vertical-align:top;">Remarks</td>
                                        <td style="padding:4px 0;">{{ $remarks }}</td>
                                    </tr>
                                @endif
                            </table>

                            <p style="margin:0 0 4px; font-weight:bold;">Status:</p>
                            <p style="margin:0 0 24px; color:#145a3a; font-weight:bold;">Fully Approved / Completed</p>

                            <p style="margin:0 0 24px;">
                                <a href="{{ $viewUrl }}" style="display:inline-block; background-color:#145a3a; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold;">
                                    View Offboarding Status
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
