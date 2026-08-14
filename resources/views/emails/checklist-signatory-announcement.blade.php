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
                            <span style="color:#ffffff; font-size:18px; font-weight:bold;">{{ $emailSubject }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px; font-size:14px; line-height:1.6; color:#1f2937;">
                            {!! $emailBody !!}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
