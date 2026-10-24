{{-- 本 panel 未掛自訂 Vite theme，Filament 預編譯 CSS 不含任意 Tailwind utility；
     版面依賴 fi-* 內建樣式與 inline style，勿改回裸 utility class --}}
<x-filament-panels::page>
    <x-filament::section heading="查詢條件">
        <form wire:submit="search" id="correction-query-form">
            {{ $this->getSchema('form') }}
        </form>

        <x-slot name="footer">
            <x-filament::button type="button" wire:click="search" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">
                查詢
            </x-filament::button>
        </x-slot>
    </x-filament::section>

    @if ($results !== null)
        <x-filament::section heading="查詢範圍">
            <dl style="display: flex; flex-wrap: wrap; gap: 1rem 2.5rem; font-size: 0.875rem;">
                <div>
                    <dt style="font-weight: 500; color: #71717a;">遊戲商</dt>
                    <dd>{{ $context['vendor'] }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">商戶</dt>
                    <dd>{{ $context['vendor_info'] }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">日期</dt>
                    <dd>{{ $context['date'] }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">時區</dt>
                    <dd>
                        UTC{{ sprintf('%+d', $results['offset']) }}
                        @if ($results['offset'] !== $results['vendor_offset'])
                            <span style="color: #b45309;">
                                （已覆寫；遊戲商 calc_time_zone 為 UTC{{ sprintf('%+d', $results['vendor_offset']) }}）
                            </span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">UTC 區間</dt>
                    <dd>{{ $results['start'] }} ～ {{ $results['end'] }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">TBR 時間欄位</dt>
                    <dd>{{ $results['time_field'] }}</dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="1. SBR 補帳明細 (correction = 1)">
            {{-- 兩個方向：關帳日這頭看「本日補了哪些資料日」，資料日那頭看「本日的帳補到哪去了」 --}}
            @foreach ([
                ['title' => '依關帳日：本日關帳補了哪些資料日（summary_time = 查詢日）', 'rows' => $results['corrections']],
                ['title' => '依資料日：本日資料被補在哪些關帳日（data_time = 查詢日）', 'rows' => $results['corrections_by_data_time']],
            ] as $block)
                <div>
                    <h4 style="font-size: 0.875rem; font-weight: 500; margin-bottom: 0.5rem;">{{ $block['title'] }}</h4>
                    @if ($block['rows'] === [])
                        <p style="font-size: 0.875rem; color: #71717a;">無調帳紀錄</p>
                    @else
                        <div style="overflow-x: auto;">
                            {{-- width: auto 貼齊內容；表頭與數字一律靠左，與第 3、4 區塊的 dl 版面一致 --}}
                            <table style="font-size: 0.875rem; text-align: left;">
                                <thead>
                                    <tr style="color: #71717a;">
                                        <th style="padding: 0.375rem 0.75rem;">資料日</th>
                                        <th style="padding: 0.375rem 0.75rem;">彙總日</th>
                                        <th style="padding: 0.375rem 1.25rem;">投注</th>
                                        <th style="padding: 0.375rem 1.25rem;">輸贏</th>
                                        <th style="padding: 0.375rem 0.75rem;">說明</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($block['rows'] as $row)
                                        <tr>
                                            <td style="padding: 0.375rem 0.75rem;">{{ $row['data_date'] }}</td>
                                            <td style="padding: 0.375rem 0.75rem;">{{ $row['summary_date'] }}</td>
                                            <td style="padding: 0.375rem 1.25rem;">{{ $row['bet'] }}</td>
                                            <td style="padding: 0.375rem 1.25rem;">{{ $row['win_lose'] }}</td>
                                            <td style="padding: 0.375rem 0.75rem; font-family: ui-monospace, monospace;">{{ $row['description'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
        </x-filament::section>

        <x-filament::section heading="2. SBR 區間合計 (summary_bet_records)">
            {{-- 調帳列的 summary_time 記在關帳整點、data_time 才是原始資料日，兩種篩法並列 --}}
            {{-- width: auto 貼齊內容；表頭與數字一律靠左，與第 3、4 區塊的 dl 版面一致 --}}
            <div style="overflow-x: auto;">
                <table style="font-size: 0.875rem; text-align: left;">
                    <thead>
                        <tr style="color: #71717a;">
                            <th style="padding: 0.375rem 0.75rem;">篩選欄位</th>
                            <th style="padding: 0.375rem 1.25rem;">投注</th>
                            <th style="padding: 0.375rem 1.25rem;">輸贏</th>
                            <th style="padding: 0.375rem 1.25rem;">jackpot</th>
                            <th style="padding: 0.375rem 1.25rem;">筆數</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['summary_time' => 'summary_time（彙總時間）', 'data_time' => 'data_time（原始資料時間）'] as $field => $label)
                            <tr>
                                <td style="padding: 0.375rem 0.75rem;">{{ $label }}</td>
                                <td style="padding: 0.375rem 1.25rem;">{{ $results['summary'][$field]['bet'] }}</td>
                                <td style="padding: 0.375rem 1.25rem;">{{ $results['summary'][$field]['win_lose'] }}</td>
                                <td style="padding: 0.375rem 1.25rem;">{{ $results['summary'][$field]['jackpot'] }}</td>
                                <td style="padding: 0.375rem 1.25rem;">{{ $results['summary'][$field]['record_count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="3. TBR 區間合計 (total_bet_records)">
            <dl style="display: flex; flex-wrap: wrap; gap: 1rem 2.5rem; font-size: 0.875rem;">
                @foreach (['bet' => '投注', 'valid_bet' => '有效投注', 'win_lose' => '輸贏', 'jackpot' => 'jackpot', 'record_count' => '筆數'] as $key => $label)
                    <div>
                        <dt style="font-weight: 500; color: #71717a;">{{ $label }}</dt>
                        <dd>{{ $results['total'][$key] }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>

        <x-filament::section heading="4. 遊戲商注單合計 ({vendor}_bet_records)">
            @if ($results['bet_record']['record_count'] === null)
                <p style="font-size: 0.875rem; color: #71717a;">{{ $results['bet_record']['note'] }}</p>
            @else
                <dl style="display: flex; flex-wrap: wrap; gap: 1rem 2.5rem; font-size: 0.875rem;">
                    @foreach (['bet' => '投注', 'valid_bet' => '有效投注', 'win_lose' => '輸贏', 'record_count' => '筆數'] as $key => $label)
                        <div>
                            <dt style="font-weight: 500; color: #71717a;">{{ $label }}</dt>
                            <dd>{{ $results['bet_record'][$key] }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #71717a;">{{ $results['bet_record']['note'] }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
