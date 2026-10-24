{{-- 本 panel 未掛自訂 Vite theme，Filament 預編譯 CSS 不含任意 Tailwind utility；
     版面依賴 fi-* 內建樣式與 inline style，勿改回裸 utility class --}}
<x-filament-panels::page>
    <x-filament::section heading="查詢條件">
        <form wire:submit="search" id="account-pool-query-form">
            {{ $this->getSchema('form') }}
        </form>

        <x-slot name="footer">
            <x-filament::button type="button" wire:click="search" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">
                查詢
            </x-filament::button>
        </x-slot>
    </x-filament::section>

    @if ($results !== null)
        @php
            $rows = $results['rows'];
            $lackingCount = count(array_filter($rows, fn (array $row): bool => $row['lack'] > 0));
        @endphp

        <x-filament::section heading="庫存總覽">
            <dl style="display: flex; flex-wrap: wrap; gap: 1rem 2.5rem; font-size: 0.875rem;">
                <div>
                    <dt style="font-weight: 500; color: #71717a;">查詢範圍</dt>
                    <dd>{{ $context['vendor'] }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">查詢時間</dt>
                    <dd>{{ $context['queried_at'] }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">商戶數</dt>
                    <dd>{{ count($rows) }}</dd>
                </div>
                <div>
                    <dt style="font-weight: 500; color: #71717a;">庫存不足</dt>
                    <dd style="{{ $lackingCount > 0 ? 'color: #b91c1c; font-weight: 600;' : 'color: #15803d;' }}">
                        {{ $lackingCount }}
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="逐商戶庫存">
            @if ($rows === [])
                <p style="font-size: 0.875rem; color: #71717a;">查詢範圍內沒有商戶（或已被「只顯示不足」濾掉）</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="font-size: 0.875rem; text-align: left;">
                        <thead>
                            <tr style="color: #71717a;">
                                <th style="padding: 0.375rem 0.75rem;">遊戲商</th>
                                <th style="padding: 0.375rem 0.75rem;">商戶</th>
                                <th style="padding: 0.375rem 0.75rem;">幣別</th>
                                <th style="padding: 0.375rem 1.25rem;">未綁定</th>
                                <th style="padding: 0.375rem 1.25rem;">目標水位</th>
                                <th style="padding: 0.375rem 1.25rem;">缺口</th>
                                <th style="padding: 0.375rem 0.75rem;">狀態</th>
                                <th style="padding: 0.375rem 0.75rem;">排程補池</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td style="padding: 0.375rem 0.75rem;">
                                        ({{ $row['vendor_id'] }}) {{ $row['vendor_name'] }}
                                        @if ($row['maintenance'])
                                            <span style="color: #b45309; font-weight: 600;">（維護中）</span>
                                        @endif
                                    </td>
                                    <td style="padding: 0.375rem 0.75rem;">({{ $row['vendor_info_id'] }}) {{ $row['vendor_info_name'] }}</td>
                                    <td style="padding: 0.375rem 0.75rem;">{{ $row['currency'] }}</td>
                                    <td style="padding: 0.375rem 1.25rem;">{{ $row['unbound'] }}</td>
                                    <td style="padding: 0.375rem 1.25rem;">{{ $row['target'] }}</td>
                                    <td style="padding: 0.375rem 1.25rem; {{ $row['lack'] > 0 ? 'color: #b91c1c; font-weight: 600;' : '' }}">{{ $row['lack'] }}</td>
                                    <td style="padding: 0.375rem 0.75rem;">
                                        @if ($row['target'] === 0)
                                            <span style="color: #71717a;">未設定水位</span>
                                        @elseif ($row['lack'] > 0)
                                            <span style="color: #b91c1c; font-weight: 600;">不足</span>
                                        @else
                                            <span style="color: #15803d;">足夠</span>
                                        @endif
                                    </td>
                                    <td style="padding: 0.375rem 0.75rem;">
                                        @if ($row['scheduled'])
                                            是
                                        @else
                                            <span style="color: #b45309;">否（僅手動補建）</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p style="margin-top: 0.5rem; font-size: 0.75rem; color: #71717a;">
                    未綁定＝帳號表 member_id = 0 且啟用；目標水位＝vendor_info.prepared_account_number（0 = 不自動補建）。
                    缺口要立即補建走「系統操作 → 補建遊戲帳號」。
                    標「維護中」的遊戲商三方 API 不可用，補建排程會跳過它，缺口要等維護結束才補得上。
                </p>
            @endif
        </x-filament::section>

        @if ($results['errors'] !== [])
            <x-filament::section heading="無法查詢的遊戲商">
                <table style="font-size: 0.875rem; text-align: left;">
                    <tbody>
                        @foreach ($results['errors'] as $error)
                            <tr>
                                <td style="padding: 0.375rem 0.75rem;">({{ $error['vendor_id'] }}) {{ $error['vendor_name'] }}</td>
                                <td style="padding: 0.375rem 0.75rem; color: #b91c1c; font-family: ui-monospace, monospace;">{{ $error['error'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
