{{-- Applies the saved light/dark choice before the page paints (no flash), and loads the dark styles. --}}
<script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}"></script>
<link rel="stylesheet" href="{{ asset('css/dark-mode.css') }}?v={{ filemtime(public_path('css/dark-mode.css')) }}">
