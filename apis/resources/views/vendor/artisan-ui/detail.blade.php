@extends('artisan-ui::layout')
@push('head')
    <link rel="preconnect" href="https://example.invalid">
    <link href="https://example.invalid/css2?family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://example.invalid/npm/@xterm/xterm@5/css/xterm.css" />
    <script src="https://example.invalid/npm/@xterm/xterm@5/lib/xterm.js"></script>
    <script src="https://example.invalid/npm/@xterm/addon-fit@0.10/lib/addon-fit.js"></script>
@endpush
@php /** @var Xtheme\ArtisanUI\Command $command */ @endphp

@section('content')
<div class="flex-1 overflow-y-auto">
    <div class="max-w-2xl lg:max-w-5xl xl:max-w-6xl mx-auto px-8 py-8" x-data="artisanCommand">

        {{-- Breadcrumb --}}
        <nav class="flex items-center gap-2 text-xs text-gray-600 mb-8">
            <a href="{{ route('artisan-ui.home') }}" class="hover:text-gray-400 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
                </svg>
                {{ __('artisan-ui::labels.commands') }}
            </a>
            <svg class="w-3 h-3 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
            @if($command->getNamespace())
                <a href="{{ route('artisan-ui.home') }}" class="hover:text-gray-400 transition-colors font-mono">
                    {{ $command->getNamespace() }}
                </a>
                <svg class="w-3 h-3 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                </svg>
            @endif
            <span class="text-gray-400 font-mono">{{ $command->getName() }}</span>
        </nav>

        {{-- Header --}}
        <div class="mb-8">
            <h1 class="text-xl font-mono font-semibold tracking-tight mb-1.5" style="color:#0f172a;">
                {{ $command->getName() }}
            </h1>
            <p class="text-sm leading-relaxed" style="color:#475569;">{{ $command->getDescription() }}</p>
        </div>

        {{-- Arguments --}}
        @if($command->hasArguments())
        <div x-data="{ open: {{ $command->hasArguments() ? 'true' : 'false' }} }"
             class="mb-3 rounded-xl border overflow-hidden" style="border-color:#e2e8f0;background:#ffffff;">
            @include('artisan-ui::partials.accordion-button', [
                'title' => __('artisan-ui::labels.arguments'),
                'count' => $command->getArgumentCount(),
                'disabled' => false,
            ])
            <div x-cloak x-show="open" x-collapse>
                <div class="px-5 py-4 border-t space-y-5" style="border-color:#e2e8f0;background:#ffffff;">
                    @foreach($command->getArguments() as $argument)
                        @if($argument->isArray())
                            @include('artisan-ui::fields.array', ['input' => $argument])
                        @else
                            @include('artisan-ui::fields.text', ['input' => $argument])
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
        @else
        <div class="mb-3 rounded-xl border overflow-hidden opacity-70" style="border-color:#e2e8f0;background:#ffffff;">
            @include('artisan-ui::partials.accordion-button', [
                'title' => __('artisan-ui::labels.arguments'),
                'count' => 0,
                'disabled' => true,
            ])
        </div>
        @endif

        {{-- Options --}}
        @if($command->hasOptions())
        <div x-data="{ open: true }" class="mb-8 rounded-xl border overflow-hidden" style="border-color:#e2e8f0;background:#ffffff;">
            @include('artisan-ui::partials.accordion-button', [
                'title' => __('artisan-ui::labels.options'),
                'count' => $command->getOptionCount(),
                'disabled' => false,
            ])
            <div x-cloak x-show="open" x-collapse>
                <div class="px-5 py-4 border-t space-y-5" style="border-color:#e2e8f0;background:#ffffff;">
                    @foreach($command->getOptions() as $option)
                        @if($option->isBoolean())
                            @include('artisan-ui::fields.boolean', ['input' => $option])
                        @elseif($option->isArray())
                            @include('artisan-ui::fields.array', ['input' => $option])
                        @else
                            @include('artisan-ui::fields.text', ['input' => $option])
                        @endif
                    @endforeach
                </div>
            </div>
        </div>
        @else
        <div class="mb-8 rounded-xl border overflow-hidden opacity-70" style="border-color:#e2e8f0;background:#ffffff;">
            @include('artisan-ui::partials.accordion-button', [
                'title' => __('artisan-ui::labels.options'),
                'count' => 0,
                'disabled' => true,
            ])
        </div>
        @endif

        {{-- Command actions --}}
        <div class="mt-6 mb-4">
            <div class="rounded-xl border p-4"
                 style="background:#ffffff;border-color:#e2e8f0;box-shadow:0 8px 24px rgba(15,23,42,.08);">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs uppercase tracking-wider" style="color:#3b82f6;">{{ __('artisan-ui::labels.command_execution') }}</p>
                        <p class="text-sm" style="color:#475569;">{{ __('artisan-ui::labels.confirm_before_run') }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        {{-- Confirm / Cancel (shown when confirming) --}}
                        <template x-if="state === 'confirming'">
                            <div class="flex items-center gap-2 w-full sm:w-auto">
                                <button
                                    @click="execute"
                                    class="inline-flex flex-1 sm:flex-none items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none"
                                    style="background:#22c55e;color:#ffffff;border:1px solid #16a34a;"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                    </svg>
                                    {{ __('artisan-ui::labels.confirm') }}
                                </button>
                                <button
                                    @click="state = 'idle'"
                                    class="inline-flex flex-1 sm:flex-none items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none"
                                    style="background:#ffffff;color:#64748b;border:1px solid #e2e8f0;"
                                >
                                    {{ __('artisan-ui::labels.cancel') }}
                                </button>
                            </div>
                        </template>

                        {{-- Run command button (default / loading) --}}
                        <template x-if="state !== 'confirming'">
                            <button
                                @click="state = 'confirming'"
                                :disabled="state === 'loading'"
                                class="inline-flex w-full sm:w-auto items-center justify-center gap-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-colors focus:outline-none"
                                style="background:#22c55e;color:#ffffff;border:1px solid #16a34a;"
                            >
                                <svg x-cloak x-show="state === 'loading'" class="animate-spin w-4 h-4" viewBox="0 0 24 24" fill="none">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                <svg x-show="state !== 'loading'" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M8 5.14v14l11-7-11-7z"/>
                                </svg>
                                <span x-text="state === 'loading' ? @js(__('artisan-ui::labels.running')) : @js(__('artisan-ui::labels.run_command'))">{{ __('artisan-ui::labels.run_command') }}</span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-4 min-h-5">
                    <div x-cloak x-show="state === 'success'" class="flex items-center gap-1.5 text-sm" style="color:#34d399;">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ __('artisan-ui::labels.completed_successfully') }}
                    </div>

                    <div x-cloak x-show="state === 'error'" class="flex items-center gap-1.5 text-sm" style="color:#f87171;">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                        </svg>
                        {{ __('artisan-ui::labels.command_failed') }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Output --}}
        <div x-cloak x-show="state === 'success' || state === 'error'" class="mt-6">
            <div class="rounded-xl overflow-hidden border" style="border-color:#30363d;background:#0d1117;box-shadow:0 10px 24px rgba(2,6,23,.18);">
                <div class="flex items-center justify-between px-4 py-2 border-b" style="border-color:#30363d;background:#161b22;">
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5">
                            <span class="w-2.5 h-2.5 rounded-full" style="background:#fb7185;"></span>
                            <span class="w-2.5 h-2.5 rounded-full" style="background:#fbbf24;"></span>
                            <span class="w-2.5 h-2.5 rounded-full" style="background:#34d399;"></span>
                        </div>
                        <span class="text-xs font-mono" style="color:#8b949e;">{{ __('artisan-ui::labels.output') }}</span>
                    </div>
                    <button @click="clear" class="text-xs transition-colors" style="color:#8b949e;">{{ __('artisan-ui::labels.clear') }}</button>
                </div>
                <div class="p-2.5" style="background:#0d1117;">
                    <div id="xterm-output"></div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    document.addEventListener('alpine:initializing', () => {
        Alpine.data('artisanCommand', () => ({
            state: 'idle',
            arguments: {!! $command->getArgumentsAsJson() !!},
            options: {!! $command->getOptionsAsJson() !!},
            route: '{{ route('artisan-ui.execution', $command->getName()) }}',
            output: '',
            term: null,
            fitAddon: null,
            fallbackErrorMessage: @js(__('artisan-ui::labels.something_went_wrong')),

            initTerminal() {
                if (this.term) return
                this.fitAddon = new FitAddon.FitAddon()
                this.term = new Terminal({
                    convertEol: true,
                    disableStdin: true,
                    scrollback: 5000,
                    fontSize: 12,
                    lineHeight: 1.3,
                    fontFamily: "'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace",
                    theme: {
                        background: '#0d1117',
                        foreground: '#e6edf3',
                        cursor: '#0d1117',
                        black: '#484f58',
                        brightBlack: '#6e7681',
                        white: '#b1bac4',
                        brightWhite: '#e6edf3',
                        green: '#3fb950',
                        brightGreen: '#56d364',
                        yellow: '#d29922',
                        brightYellow: '#e3b341',
                        red: '#f85149',
                        brightRed: '#ff7b72',
                        cyan: '#39c5cf',
                        brightCyan: '#56d4dd',
                        blue: '#58a6ff',
                        brightBlue: '#79c0ff',
                        magenta: '#bc8cff',
                        brightMagenta: '#d2a8ff',
                    },
                })
                this.term.loadAddon(this.fitAddon)
                const el = document.getElementById('xterm-output')
                this.term.open(el)

                // Refit whenever the container gains actual dimensions (e.g. after x-show reveals it)
                const ro = new ResizeObserver(() => {
                    if (el.offsetWidth > 0) this.fitAddon.fit()
                })
                ro.observe(el)
                window.addEventListener('resize', () => this.fitAddon && this.fitAddon.fit())
            },

            execute() {
                if (this.state === 'loading') return
                this.state = 'loading'
                axios.post(this.route, { arguments: this.arguments, options: this.options })
                    .then(r => this.onSuccess(r))
                    .catch(e => this.onFailure(e))
            },
            onSuccess(response) {
                this.output = response.data.output || ''
                this.state = response.data.success ? 'success' : 'error'
                this.$nextTick(() => this.renderOutput(this.output))
            },
            onFailure(error) {
                this.output = error.response?.data?.message || this.fallbackErrorMessage
                this.state = 'error'
                this.$nextTick(() => this.renderOutput(this.output))
            },
            renderOutput(text) {
                this.initTerminal()
                this.term.reset()
                this.term.write(text || @js(__('artisan-ui::labels.no_output')))
            },
            clear() {
                this.state = 'idle'
                this.output = ''
                if (this.term) this.term.reset()
            },            updateArrayValue(type, name, value, index) {
                const arr = this[type][name] || []
                arr[index] = value
                this[type][name] = arr
            },
            deleteArrayValue(type, name, index) {
                const arr = this[type][name] || []
                arr.splice(index, 1)
                this[type][name] = arr
            },
        }))
    })
</script>
@endsection
