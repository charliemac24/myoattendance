@props(['heading', 'items' => []])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
        <td style="font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:16px;font-weight:700;color:#111827;padding-bottom:12px;">{{ $heading }}</td>
    </tr>
    @foreach ($items as $index => $item)
        <tr>
            <td style="padding-bottom:{{ $loop->last ? '0' : '12px' }};">
                <table role="presentation" cellpadding="0" cellspacing="0">
                    <tr>
                        <td width="24" valign="top" style="padding-right:12px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="24" height="24" align="center" valign="middle" style="background-color:#f7f8fa;border-radius:12px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;color:#1263df;line-height:24px;">{{ $index + 1 }}</td>
                                </tr>
                            </table>
                        </td>
                        <td style="font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:14px;color:#111827;line-height:1.45;">{{ $item }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    @endforeach
</table>
