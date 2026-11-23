@props(['title' => 'MYO Attendance'])
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $title }}</title>
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,600,700">
<!--[if mso]>
<style type="text/css">
body, table, td, a { font-family: Arial, Helvetica, sans-serif !important; }
</style>
<![endif]-->
<style>
    body { margin: 0; padding: 0; background-color: #f4f6f8; width: 100% !important; -webkit-text-size-adjust: 100%; }
    table { border-collapse: collapse; }
    img { border: 0; display: block; }
    a { text-decoration: none; }
    @media only screen and (max-width: 640px) {
        .myo-email-wrap { width: 100% !important; }
        .myo-email-card { padding: 24px !important; }
        .myo-email-outer { padding: 24px 16px !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;">
    <tr>
        <td align="center" class="myo-email-outer" style="padding:40px 16px;">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" class="myo-email-wrap" style="width:640px;max-width:640px;">
                <tr>
                    <td style="padding-bottom:24px;">
                        <img src="{{ asset('images/email/myo-logo-on-light.png') }}" width="150" alt="MYO Attendance" style="display:block;width:150px;height:auto;">
                    </td>
                </tr>
                <tr>
                    <td class="myo-email-card" style="background-color:#ffffff;border:1px solid #dde2e8;border-radius:10px;padding:40px;font-family:'DM Sans',Arial,Helvetica,sans-serif;">
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td style="padding-top:24px;text-align:center;font-family:'DM Sans',Arial,Helvetica,sans-serif;">
                        <p style="margin:0 0 4px;font-size:14px;font-weight:600;color:#111827;">MYO Attendance</p>
                        <p style="margin:0 0 4px;font-size:13px;color:#5f6b7a;">by MYO Systems</p>
                        <p style="margin:0;font-size:13px;color:#5f6b7a;line-height:1.45;">This is an automated account email from MYO Attendance.</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
