@props(['user', 'activeTab' => '', 'pageTitle' => null, 'showHeader' => true])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800">
    <link rel="stylesheet" href="{{ asset('css/auth-shared.css') }}?v={{ filemtime(public_path('css/auth-shared.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/app-shared.css') }}?v={{ filemtime(public_path('css/app-shared.css')) }}">
    <x-theme-head />
    <style>
        body { background: #f9fafb; display: flex; justify-content: center; overflow-x: hidden; }
        .app-screen { display: flex; flex-direction: column; min-width: 0; max-width: 100%; }
    </style>
    {{ $head ?? '' }}
</head>
<body>
    <div class="app-screen">
        @if ($showHeader)
            <x-app.header :user="$user" />
        @endif
        @if (! request()->is('billing'))
            <x-app.subscription-banner :user="$user" />
        @endif
        <main class="app-scroll">
            {{ $slot }}
        </main>
        <x-app.bottom-nav :active="$activeTab" />
    </div>

    <script src="{{ asset('js/auth-shared.js') }}?v={{ filemtime(public_path('js/auth-shared.js')) }}"></script>
    <script src="{{ asset('js/app-shared.js') }}?v={{ filemtime(public_path('js/app-shared.js')) }}"></script>
    {{ $scripts ?? '' }}
</body>
</html>
