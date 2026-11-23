<x-emails.layout title="Verify your email · MYO Attendance">
    <p style="margin:0 0 20px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:24px;font-weight:700;color:#111827;line-height:1.25;">Welcome to MYO Attendance</p>

    <p style="margin:0 0 16px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:16px;color:#111827;line-height:1.55;">{{ $greeting }}</p>
    <p style="margin:0 0 16px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:16px;color:#111827;line-height:1.55;">Thanks for creating your MYO Attendance account.</p>
    <p style="margin:0 0 24px;font-family:'DM Sans',Arial,Helvetica,sans-serif;font-size:16px;color:#111827;line-height:1.55;">Please verify your email address to finish setting up your account and continue to MYO Attendance.</p>

    <x-emails.button :url="$url" label="Verify Email Address" />

    <x-emails.info-box
        heading="Your 30-day free trial"
        :footer-label="$trialEndsAt ? 'Trial ends' : null"
        :footer-value="$trialEndsAt"
    >
        <p style="margin:0 0 8px;">Your MYO Attendance account includes a 30-day free trial.</p>
        <p style="margin:0 0 8px;">After the trial, MYO Attendance is {{ $monthlyPrice }}/month to continue using the service.</p>
        <p style="margin:0;">SMS Notifications is an optional paid add-on and is not included in the {{ $monthlyPrice }} base subscription.</p>
    </x-emails.info-box>

    <x-emails.steps
        heading="What happens next?"
        :items="[
            'Verify your email address',
            'Sign in to MYO Attendance',
            'Set up your organization',
            'Start using MYO Attendance',
        ]"
    />

    <x-emails.note-box>
        If you didn't create a MYO Attendance account, you can safely ignore this email.
    </x-emails.note-box>

    <x-emails.divider />

    <x-emails.fallback-url :url="$url" />
</x-emails.layout>
