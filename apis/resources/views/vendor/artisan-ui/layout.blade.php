<!doctype html>
<html lang="en" class="h-full">
<head>
    <title>{{ __('artisan-ui::labels.artisan_ui') }}</title>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link href="{{ asset('vendor/artisan-ui/artisan-ui.css') }}" rel="stylesheet">
    <style>[x-cloak] { display: none !important; }</style>
    <script defer src="https://example.invalid/npm/@alpinejs/collapse@3.14.8/dist/cdn.min.js"></script>
    <script defer src="https://example.invalid/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <script src="https://example.invalid/npm/axios@1.7.9/dist/axios.min.js"></script>
    @stack('head')
</head>
<body class="h-full antialiased" style="background:#f1f5f9;color:#0f172a;">

<div class="h-full flex flex-col">

    {{-- Top bar --}}
    <header class="flex-shrink-0 h-12 border-b flex items-center px-4 gap-4 z-20" style="border-color:#dbeafe;background:#ffffff;">
        <a href="{{ route('artisan-ui.home') }}" class="flex items-center gap-2 group">
            <span class="flex items-center justify-center w-6 h-6 rounded ring-1 transition-colors"
                  style="background:#eff6ff;color:#2563eb;ring-color:#bfdbfe;">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 7.5l3 2.25-3 2.25m4.5 0h3M3.75 21h16.5a1.5 1.5 0 001.5-1.5v-15a1.5 1.5 0 00-1.5-1.5H3.75a1.5 1.5 0 00-1.5 1.5v15a1.5 1.5 0 001.5 1.5z" />
                </svg>
            </span>
            <span class="text-sm font-semibold" style="color:#0f172a;">{{ __('artisan-ui::labels.artisan_ui') }}</span>
        </a>
        <div class="h-4 w-px" style="background:#dbeafe;"></div>
        <span class="text-xs font-mono" style="color:#64748b;">{{ config('app.env') }}</span>
        <div class="ml-auto flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 text-xs rounded-full px-2.5 py-0.5 ring-1"
                  style="color:#047857;background:#ecfdf5;ring-color:#a7f3d0;">
                <span class="w-1.5 h-1.5 rounded-full animate-pulse" style="background:#10b981;"></span>
                {{ __('artisan-ui::labels.ready') }}
            </span>
        </div>
    </header>

    {{-- Body --}}
    <div class="flex flex-1 overflow-hidden">
        @yield('content')
    </div>

</div>

</body>
</html>
