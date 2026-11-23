<x-emails.layout title="Reset your password · MYO Attendance">
    <p style="margin:0 0 20px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:24px;font-weight:700;color:#111827;line-height:1.25;">Reset your password</p>

    <p style="margin:0 0 16px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:16px;color:#111827;line-height:1.55;">{{ $greeting }}</p>
    <p style="margin:0 0 24px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:16px;color:#111827;line-height:1.55;">We received a request to reset the password for your MYO Attendance account.</p>

    <x-emails.button :url="$url" label="Reset Password" />

    <p style="margin:0 0 24px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:14px;color:#5f6b7a;line-height:1.5;">This password reset link will expire in {{ $expireMinutes }} minutes.</p>

    <x-emails.note-box>
        If you didn't request a password reset, you can safely ignore this email. Your password will remain unchanged.
        <br><br>
        <span style="color:#5f6b7a;font-size:13px;">MYO Attendance will never ask you to send your password by email.</span>
    </x-emails.note-box>

    <x-emails.divider />

    <x-emails.fallback-url :url="$url" />
</x-emails.layout>
