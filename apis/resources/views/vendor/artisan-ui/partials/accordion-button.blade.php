@if($disabled ?? false)
    <div class="flex items-center justify-between px-4 py-3 select-none cursor-not-allowed border" style="border-color:#e2e8f0;background:#f8fafc;">
        <span class="text-sm font-medium" style="color:#64748b;">{{ $title }}</span>
        <span class="text-xs rounded-full px-2 py-0.5 tabular-nums" style="color:#475569;background:#e2e8f0;">{{ $count ?? 0 }}</span>
    </div>
@else
    <button
        @click="open = !open"
        class="w-full flex items-center justify-between px-4 py-3 transition-colors group border"
        :style="open
            ? 'background:#eff6ff;border-color:#bfdbfe;box-shadow:inset 3px 0 0 #3b82f6;'
            : 'background:#ffffff;border-color:#e2e8f0;box-shadow:inset 0 0 0 0 transparent;'"
        @mouseenter="if (!open) { $el.style.background = '#eff6ff'; $el.style.borderColor = '#bfdbfe'; $el.style.boxShadow = 'inset 3px 0 0 #3b82f6' }"
        @mouseleave="if (!open) { $el.style.background = '#ffffff'; $el.style.borderColor = '#e2e8f0'; $el.style.boxShadow = 'inset 0 0 0 0 transparent' }"
    >
        <div class="flex items-center gap-2.5">
            <span class="text-sm font-medium transition-colors" style="color:#0f172a;">{{ $title }}</span>
            <span class="text-xs rounded-full px-2 py-0.5 tabular-nums"
                  :style="open ? 'color:#1d4ed8;background:#dbeafe;' : 'color:#334155;background:#e2e8f0;'">{{ $count ?? 0 }}</span>
        </div>
        <svg class="w-4 h-4 transition-transform duration-200" style="color:#334155;"
             :class="open ? 'rotate-180' : ''"
             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
    </button>
@endif
