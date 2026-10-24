@php /** @var Xtheme\ArtisanUI\CommandInput $input */ @endphp

<div>
    <label class="flex items-center gap-2 text-sm mb-1.5">
        <span class="font-medium" style="color:#0f172a;">{{ $input->getName() }}</span>
        @if($input->isRequired())
            <span class="text-[10px] font-semibold uppercase tracking-wide text-amber-400 bg-amber-400/10 border border-amber-400/20 rounded px-1.5 py-0.5">{{ __('artisan-ui::labels.required') }}</span>
        @endif
    </label>
    <input
        x-model="{{ $input->getAbsoluteKey() }}"
        type="text"
        class="w-full rounded-lg px-3 py-2 text-sm transition focus:outline-none focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500"
        style="background:#ffffff;border:1px solid #cbd5e1;color:#0f172a;"
        placeholder="{{ $input->getDefaultToDisplay() ?: __('artisan-ui::labels.enter_value') }}"
    >
    @if(!! $input->getDescription())
        <p class="mt-1.5 text-xs" style="color:#475569;">{{ $input->getDescription() }}</p>
    @endif
</div>
