@props(['heading', 'footerLabel' => null, 'footerValue' => null])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef5ff;border-radius:8px;margin:0 0 24px;">
    <tr>
        <td style="padding:16px;font-family:'DM Sans',Arial,Helvetica,sans-serif;">
            <table role="presentation" cellpadding="0" cellspacing="0">
                <tr>
                    <td width="20" valign="top" style="padding-right:12px;padding-top:1px;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td width="20" height="20" align="center" valign="middle" style="background-color:#1263df;border-radius:10px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#ffffff;line-height:20px;">i</td>
                            </tr>
                        </table>
                    </td>
                    <td style="font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#24456f;">
                        <p style="margin:0 0 8px;font-weight:700;">{{ $heading }}</p>
                        {{ $slot }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @if ($footerLabel)
        <tr>
            <td style="padding:0 16px 16px 48px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:13px;font-weight:600;color:#24456f;">{{ $footerLabel }}</td>
                        <td align="right" style="font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:13px;color:#24456f;">{{ $footerValue }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    @endif
</table>
