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
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">Password Reset Requested</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px;">
                            <p style="margin:0 0 16px;">Hello {{ $userName }},</p>
                            <p style="margin:0 0 20px;">
                                We received a request to reset the password for your CIM Offboarding account. Click the button below to choose a new password.
                            </p>

                            <p style="margin:0 0 24px;">
                                <a href="{{ $resetUrl }}" style="display:inline-block; background-color:#145a3a; color:#ffffff; text-decoration:none; padding:12px 24px; border-radius:8px; font-weight:bold;">
                                    Reset Password
                                </a>
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#fef9c3; border:1px solid #fde68a; border-radius:8px; margin-bottom:20px;">
                                <tr>
                                    <td style="padding:16px 20px; font-size:14px;">
                                        <p style="margin:0; color:#92400e;">
                                            <strong>This link is valid for 5 minutes only.</strong> After it expires you'll need to request a new one.
                                        </p>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px; font-size:13px; color:#6b7280;">
                                If the button above doesn't work, copy and paste this link into your browser:
                            </p>
                            <p style="margin:0 0 24px; font-size:13px; word-break:break-all;">
                                <a href="{{ $resetUrl }}" style="color:#145a3a;">{{ $resetUrl }}</a>
                            </p>

                            <p style="margin:0; font-size:13px; color:#6b7280;">
                                If you did not request a password reset, no action is needed — you can safely ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
