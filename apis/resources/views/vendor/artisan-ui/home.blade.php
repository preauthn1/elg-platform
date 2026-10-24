@extends('artisan-ui::layout')
@php /** @var Illuminate\Support\Collection $commands */ @endphp
@php /** @var Xtheme\ArtisanUI\Command $command */ @endphp

@section('content')
<div class="flex-1 overflow-y-auto">
    <div
        x-data="{
            selected: null,
            search: '',
            selectNs(ns) { this.selected = this.selected === ns ? null : ns },
            matchesSearch(name) {
                return this.search === '' || name.toLowerCase().includes(this.search.toLowerCase())
            }
        }"
        class="max-w-2xl lg:max-w-5xl xl:max-w-6xl mx-auto px-8 py-8"
    >

        {{-- Breadcrumb --}}
        <nav class="flex items-center gap-2 text-xs text-gray-600 mb-8">
            <a href="{{ route('artisan-ui.home') }}" class="hover:text-gray-400 transition-colors flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/>
                </svg>
                {{ __('artisan-ui::labels.commands') }}
            </a>
        </nav>

        {{-- Header --}}
        <div class="mb-8">
            <h1 class="text-2xl font-mono font-semibold tracking-tight mb-1" style="color:#0f172a;">{{ __('artisan-ui::labels.commands') }}</h1>
            <p class="text-sm leading-relaxed" style="color:#64748b;">{{ __('artisan-ui::labels.browse_and_run_commands') }}</p>
        </div>

        {{-- Command browser panel --}}
        <div class="rounded-xl border border-white/10 overflow-hidden">
            <div class="flex w-full overflow-hidden min-h-[70vh]">

                {{-- Sidebar --}}
                <nav class="flex-shrink-0 w-56 border-r border-white/10 bg-gray-950/60 flex flex-col overflow-hidden">
                    <div class="px-4 pt-4 pb-2 flex-shrink-0">
                        <p class="text-[11px] font-semibold uppercase tracking-widest px-2 mb-1 text-gray-500">{{ __('artisan-ui::labels.namespaces') }}</p>
                    </div>

                    <div class="flex-1 overflow-y-auto px-2 pb-4 space-y-px">
                        <button
                            @click="selected = null"
                            :style="selected === null
                                ? 'background:#eff6ff;color:#0f172a;border-color:#bfdbfe;box-shadow:inset 3px 0 0 #3b82f6;'
                                : 'background:transparent;color:#94a3b8;border-color:transparent;box-shadow:none;'"
                            class="w-full text-left flex items-center justify-between px-3 py-2 rounded-md text-xs font-medium border transition-colors"
                            @mouseenter="if (selected !== null) { $el.style.background = '#0b1220'; $el.style.color = '#cbd5e1' }"
                            @mouseleave="if (selected !== null) { $el.style.background = 'transparent'; $el.style.color = '#94a3b8' }"
                        >
                            <span>{{ __('artisan-ui::labels.all_commands') }}</span>
                            <span class="text-[10px] tabular-nums" :style="selected === null ? 'color:#1d4ed8' : 'color:#64748b'">
                                {{ $commands->flatten(1)->count() }}
                            </span>
                        </button>

                        <div class="my-2 border-t border-white/10"></div>

                        @foreach($commands as $namespace => $commandGroup)
                            <button
                                @click="selectNs('{{ $namespace }}')"
                                :style="selected === '{{ $namespace }}'
                                    ? 'background:#eff6ff;color:#0f172a;border-color:#bfdbfe;box-shadow:inset 3px 0 0 #3b82f6;'
                                    : 'background:transparent;color:#94a3b8;border-color:transparent;box-shadow:none;'"
                                class="w-full text-left flex items-center justify-between px-3 py-2 rounded-md text-xs font-mono border transition-colors"
                                @mouseenter="if (selected !== '{{ $namespace }}') { $el.style.background = '#0b1220'; $el.style.color = '#cbd5e1' }"
                                @mouseleave="if (selected !== '{{ $namespace }}') { $el.style.background = 'transparent'; $el.style.color = '#94a3b8' }"
                            >
                                <span class="truncate">{{ $namespace ?: __('artisan-ui::labels.global_namespace') }}</span>
                                <span class="text-[10px] tabular-nums flex-shrink-0 ml-1"
                                      :style="selected === '{{ $namespace }}' ? 'color:#1d4ed8' : 'color:#64748b'">
                                    {{ $commandGroup->count() }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </nav>

                {{-- Main panel --}}
                <div class="flex-1 flex flex-col overflow-hidden" style="background:#f8fafc;">
                    <div class="flex-shrink-0 border-b px-4 py-2.5" style="border-color:#dbeafe;background:#0f172a;">
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 w-10 inline-flex items-center justify-center">
                                <svg class="w-4 h-4 text-gray-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803a7.5 7.5 0 0010.607 0z"/>
                                </svg>
                            </span>
                            <input
                                x-model="search"
                                type="search"
                                placeholder="{{ __('artisan-ui::labels.search_commands_placeholder') }}"
                                class="w-full h-10 rounded-lg pl-10 pr-3 text-sm focus:outline-none transition"
                                style="color:#e2e8f0;background:#0b1220;border:1px solid #334155;"
                            >
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto">
                        @foreach($commands as $namespace => $commandGroup)
                            <div x-show="selected === null || selected === '{{ $namespace }}'">
                                <div x-show="selected === null" class="sticky top-0 z-10 border-b px-5 py-1.5" style="background:#f1f5f9;border-color:#cbd5e1;">
                                    <span class="text-[10px] font-semibold uppercase tracking-widest font-mono" style="color:#475569;">
                                        {{ $namespace ?: __('artisan-ui::labels.global_namespace') }}
                                    </span>
                                </div>

                                @foreach($commandGroup as $command)
                                    <div x-show="matchesSearch('{{ $command->getName() }}')">
                                        <a
                                            href="{{ route('artisan-ui.detail', $command->getName()) }}"
                                            class="group flex items-center gap-3.5 px-6 py-3.5 border-b transition-colors"
                                            style="border-color:#e2e8f0;background:#ffffff;box-shadow:inset 0 0 0 0 transparent;"
                                            @mouseenter="$el.style.background='#eff6ff';$el.style.borderColor='#bfdbfe';$el.style.boxShadow='inset 3px 0 0 #3b82f6'"
                                            @mouseleave="$el.style.background='#ffffff';$el.style.borderColor='#e2e8f0';$el.style.boxShadow='inset 0 0 0 0 transparent'"
                                        >
                                            <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full mt-0.5" style="background:#334155;"></span>
                                            <div class="min-w-0 flex-1">
                                                <p class="font-mono text-sm font-semibold tracking-tight leading-5" style="color:#0f172a;">
                                                    {{ $command->getName() }}
                                                </p>
                                                @if($command->getDescription())
                                                    <p class="text-xs truncate mt-0.5 leading-5" style="color:#334155;">
                                                        {{ $command->getDescription() }}
                                                    </p>
                                                @endif
                                            </div>
                                            <svg class="flex-shrink-0 w-4 h-4" style="color:#475569;"
                                                 viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                                            </svg>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach

                        <div
                            x-cloak
                            x-show="search !== '' && !{{ collect($commands->flatten(1))->map(fn($c) => "'{$c->getName()}'")->join(',') }}.some(n => n.toLowerCase().includes(search.toLowerCase()))"
                            class="flex flex-col items-center justify-center py-24 text-center"
                        >
                            <svg class="w-8 h-8 text-gray-700 mb-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803a7.5 7.5 0 0010.607 0z"/>
                            </svg>
                            <p class="text-sm text-gray-500">
                                {{ __('artisan-ui::labels.no_commands_match') }}
                                <span class="text-gray-400 font-mono">"<span x-text="search"></span>"</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>
@endsection
