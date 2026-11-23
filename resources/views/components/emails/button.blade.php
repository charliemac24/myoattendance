@props(['url', 'label'])
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;">
    <tr>
        <td style="border-radius:8px;background-color:#2563eb;">
            <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:15px 28px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">{{ $label }}</a>
        </td>
    </tr>
</table>
