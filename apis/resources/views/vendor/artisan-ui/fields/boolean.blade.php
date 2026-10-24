@php /** @var Xtheme\ArtisanUI\CommandOption $input */ @endphp
@php
    $sizeTokens = [
        'sm' => [
            'track' => 'h-6 w-10',
            'thumb' => 'h-4 w-4',
            'icon' => 'w-2.5 h-2.5',
            'on' => 'transform:translateX(18px);',
            'off' => 'transform:translateX(2px);',
        ],
        'md' => [
            'track' => 'h-7 w-12',
            'thumb' => 'h-5 w-5',
            'icon' => 'w-3 h-3',
            'on' => 'transform:translateX(22px);',
            'off' => 'transform:translateX(2px);',
        ],
        'lg' => [
            'track' => 'h-8 w-14',
            'thumb' => 'h-6 w-6',
            'icon' => 'w-3.5 h-3.5',
            'on' => 'transform:translateX(26px);',
            'off' => 'transform:translateX(2px);',
        ],
    ];

    $switchSize = config('artisan-ui.switch_size', 'md');
    $size = $sizeTokens[$switchSize] ?? $sizeTokens['md'];
@endphp

<div
    x-model="{{ $input->getAbsoluteKey() }}"
    class="flex items-center justify-between gap-4 rounded-lg border px-3 py-2 transition-colors cursor-pointer"
    :style="{{ $input->getAbsoluteKey() }}
        ? 'background:#ecfdf5;border-color:#86efac;'
        : 'background:#ffffff;border-color:#e2e8f0;'"
    @click="$dispatch('input', ! {{ $input->getAbsoluteKey() }})"
    @keydown.enter.prevent="$dispatch('input', ! {{ $input->getAbsoluteKey() }})"
    @keydown.space.prevent="$dispatch('input', ! {{ $input->getAbsoluteKey() }})"
    tabindex="0"
>
    <div class="min-w-0">
        <p
            id="{{ $input->getName() }}-label"
            class="text-sm font-medium"
            :style="{{ $input->getAbsoluteKey() }} ? 'color:#065f46;' : 'color:#0f172a;'"
        >{{ $input->getName() }}</p>
        <p
            class="text-xs mt-0.5"
            :style="{{ $input->getAbsoluteKey() }} ? 'color:#047857;' : 'color:#475569;'"
        >{{ $input->getDescription() ?? __('artisan-ui::labels.activates_option', ['name' => $input->getName()]) }}</p>
    </div>

    <button
        type="button"
        role="switch"
        :aria-checked="{{ $input->getAbsoluteKey() }} ? 'true' : 'false'"
        aria-labelledby="{{ $input->getName() }}-label"
        @click.stop="$dispatch('input', ! {{ $input->getAbsoluteKey() }})"
        class="relative inline-flex items-center flex-shrink-0 rounded-full cursor-pointer border-2 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-emerald-500 {{ $size['track'] }}"
        :style="{{ $input->getAbsoluteKey() }}
            ? 'background:#22c55e;border-color:#16a34a;'
            : 'background:#94a3b8;border-color:#64748b;'"
    >
        <span class="sr-only" x-text="{{ $input->getAbsoluteKey() }} ? @js(__('artisan-ui::labels.enabled')) : @js(__('artisan-ui::labels.disabled'))">
            {{ __('artisan-ui::labels.toggle_option', ['name' => $input->getName()]) }}
        </span>
        <span
            aria-hidden="true"
            class="pointer-events-none grid place-items-center rounded-full bg-white shadow ring-0 transition-transform duration-200 {{ $size['thumb'] }}"
            :style="{{ $input->getAbsoluteKey() }} ? @js($size['on']) : @js($size['off'])"
        >
            <svg x-show="{{ $input->getAbsoluteKey() }}" class="{{ $size['icon'] }} text-emerald-600" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.414l-7.2 7.2a1 1 0 01-1.414 0l-3.2-3.2a1 1 0 111.414-1.414l2.493 2.493 6.493-6.493a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <svg x-show="!{{ $input->getAbsoluteKey() }}" class="{{ $size['icon'] }} text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" />
            </svg>
        </span>
    </button>
</div>
