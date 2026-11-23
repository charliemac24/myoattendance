<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ config('app.name') }}</title><link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}"><link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}"><link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}"><link rel="preconnect" href="https://fonts.bunny.net"><link rel="stylesheet" href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700,800"></head>
<body><div id="app"></div>@vite(['resources/css/app.css', 'resources/js/app.js'])</body>
</html>
