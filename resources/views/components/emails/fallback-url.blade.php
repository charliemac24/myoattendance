@props(['url'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f7f8fa;border:1px solid #dde2e8;border-radius:8px;">
    <tr>
        <td style="padding:16px;font-family:'DM Sans',Arial,Helvetica,sans-serif;">
            <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#111827;">Having trouble with the button?</p>
            <p style="margin:0 0 8px;font-size:14px;color:#5f6b7a;line-height:1.45;">Copy and paste this link into your browser:</p>
            <p style="margin:0;font-size:12px;line-height:1.55;word-break:break-all;">
                <a href="{{ $url }}" style="color:#1263df;text-decoration:none;word-break:break-all;">{{ $url }}</a>
            </p>
        </td>
    </tr>
</table>
