@props(['bg' => '#f7f8fa', 'color' => '#111827'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:{{ $bg }};border-radius:8px;margin:0 0 24px;">
    <tr>
        <td style="padding:16px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:{{ $color }};">
            {{ $slot }}
        </td>
    </tr>
</table>
