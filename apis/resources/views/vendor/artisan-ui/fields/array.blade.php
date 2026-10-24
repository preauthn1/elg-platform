@php /** @var Xtheme\ArtisanUI\CommandInput $input */ @endphp

<div>
    <label class="flex items-center gap-2 text-sm mb-1.5">
        <span class="font-medium" style="color:#0f172a;">{{ $input->getName() }}</span>
        @if($input->isRequired())
            <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-400 bg-amber-400/10 border border-amber-400/20 rounded px-1.5 py-0.5">{{ __('artisan-ui::labels.required') }}</span>
        @endif
        <span class="text-[10px] font-semibold uppercase tracking-wide text-sky-400 bg-sky-400/10 border border-sky-400/20 rounded px-1.5 py-0.5">{{ __('artisan-ui::labels.array') }}</span>
    </label>
    <div class="space-y-2">
        <template x-for="(value, index) in [...({{ $input->getAbsoluteKey() }} || []), '']">
            <div class="relative flex items-center gap-2">
                <input
                    :value="value"
                    type="text"
                    class="flex-1 rounded-lg px-3 py-2 text-sm transition focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
                    style="background:#ffffff;border:1px solid #cbd5e1;color:#0f172a;"
                    placeholder="{{ $input->getDefaultToDisplay() ?: __('artisan-ui::labels.enter_value') }}"
                    @input="event => { updateArrayValue('{{ $input->getType() }}', '{{ $input->getName() }}', event.target.value, index) }"
                >
                <button
                    x-show="index < ([...({{ $input->getAbsoluteKey() }} || []), ''].length - 1)"
                    @click="deleteArrayValue('{{ $input->getType() }}', '{{ $input->getName() }}', index)"
                    class="flex-shrink-0 w-7 h-7 flex items-center justify-center rounded-md text-gray-500 hover:text-red-400 hover:bg-red-400/10 transition"
                    title="{{ __('artisan-ui::labels.remove') }}"
                >
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
                <div x-show="index >= ([...({{ $input->getAbsoluteKey() }} || []), ''].length - 1)" class="w-7 h-7 flex-shrink-0"></div>
            </div>
        </template>
    </div>
    @if(!! $input->getDescription())
        <p class="mt-1.5 text-xs" style="color:#475569;">{{ $input->getDescription() }}</p>
    @endif
</div>
