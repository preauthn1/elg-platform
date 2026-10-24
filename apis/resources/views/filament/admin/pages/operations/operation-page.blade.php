{{-- 本 panel 未掛自訂 Vite theme，Filament 預編譯 CSS 不含任意 Tailwind utility；
     版面依賴 fi-* 內建樣式（section footer 等）與 inline style，勿改回裸 utility class --}}
<x-filament-panels::page>
    <x-filament::section heading="執行參數">
        {{-- 表單按 Enter 走同一顆 action，否則會繞過舊日期的確認視窗直接執行 --}}
        <form wire:submit="mountAction('execute')" id="operation-form">
            {{ $this->getSchema('form') }}
        </form>

        {{-- 不可用 form 屬性關聯表單：Filament button 元件會把 form 視為自身參數而不輸出 HTML 屬性 --}}
        <x-slot name="footer">
            {{ $this->executeAction }}
        </x-slot>
    </x-filament::section>

    @php($log = $this->getCurrentLog())

    @if ($log !== null)
        <div @if ($this->isPolling()) wire:poll.5s @endif>
            <x-filament::section heading="最近執行 #{{ $log->id }}">
                <x-slot name="afterHeader">
                    {{-- 中斷按鈕與狀態 badge 並排。可見性必須在這裡自己判：直印 action 走的是
                         Action::toHtml()，整條路徑不讀 visible()（Filament 只在 ActionGroup、schema 的
                         Actions 元件與測試輔助裡過濾），少了這道判斷，收尾後按鈕仍會留在畫面上 --}}
                    <div style="display: flex; align-items: center; gap: 0.75rem;">
                        <x-filament::badge :color="$log->status->color()">
                            {{ $log->status->label() }}
                        </x-filament::badge>

                        @if ($this->cancelAction->isVisible())
                            {{ $this->cancelAction }}
                        @endif
                    </div>
                    {{-- 不印 queue_job_runs.status：這頁的兩張表在同一交易收尾，狀態恆等於左邊那個
                         badge，並排只會讓操作者以為是兩件事（執行中＋未處理）。該欄仍供 isPolling() 用 --}}
                </x-slot>

                {{-- inline style 禁用含括號的 CSS 函式如 rgb 與 minmax：
                     括號會干擾 Livewire/Blade 對後續 @if 的參數匹配，造成編譯錯誤 --}}
                <dl style="display: flex; flex-wrap: wrap; gap: 1rem 2.5rem; font-size: 0.875rem;">
                    <div>
                        <dt style="font-weight: 500; color: #71717a;">執行者</dt>
                        <dd>{{ $log->user->username }}</dd>
                    </div>
                    <div>
                        <dt style="font-weight: 500; color: #71717a;">參數</dt>
                        <dd>{{ $log->params ? json_encode($log->params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '-' }}</dd>
                    </div>
                    <div>
                        <dt style="font-weight: 500; color: #71717a;">建立時間</dt>
                        <dd>{{ $this->displayTime($log->created_at) }}</dd>
                    </div>
                    <div>
                        <dt style="font-weight: 500; color: #71717a;">開始</dt>
                        <dd>{{ $this->displayTime($log->started_at) }}</dd>
                    </div>
                    <div>
                        <dt style="font-weight: 500; color: #71717a;">結束</dt>
                        <dd>{{ $this->displayTime($log->finished_at) }}</dd>
                    </div>
                </dl>

                @if (filled($log->queueJobRun?->output))
                    {{-- wire:key 綁輸出雜湊：內容一變就整個換掉元素、x-init 重跑，捲到最底看最新進度 --}}
                    <pre wire:key="output-{{ md5($log->queueJobRun->output) }}" x-data x-init="$el.scrollTop = $el.scrollHeight" style="margin-top: 1.25rem; max-height: 60vh; overflow-y: auto; white-space: pre-wrap; word-break: break-all; border-radius: 0.5rem; background: #18181b0d; padding: 1rem; font-size: 0.75rem;">{{ $log->queueJobRun->output }}</pre>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
