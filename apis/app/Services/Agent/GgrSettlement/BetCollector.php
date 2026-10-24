<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Helpers\Common;
use App\Models\GgrVendorSetting;
use App\Models\TotalBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 4123 逐筆落階加權：把直屬會員的逐筆注單算成「加權總和」（公式③）。
 *
 * 資料源＝total_bet_records 逐筆（payout_time 認列，與 SBR 同基準；UTC）。
 * 設定維度降維為 vendor_id × games.type_id（vendor_info_id 僅設定端顯示替身，
 * migration 註解明定 vendor_id 為結算彙總鍵）；tbr.game_id → games.type_id
 * 取遊戲類型，join 不到（game_id=0 查無遊戲）整筆不計。
 *
 * 逐筆規則（工單 4123 定案）：
 * 1. |GGR| 落階（GGR=−win_lose；threshold 由高往低取第一個達標階；
 *    低於最低階整筆不納入；threshold 0＝不限制全收）
 * 2. 保留正負 × 該階加權%
 * 3. 加權後過雙門檻（皆看絕對值、0＝不限制）：|加權後| < min_winlose 不計；
 *    |加權後| > max_ggr 截斷保留正負
 * 排除：excluded_game_ids 內的遊戲、組合 status=0、查無設定的 vendor×type。
 */
class BetCollector
{
    private const int CHUNK_SIZE = 5000;

    /**
     * @param  array<int, int>  $memberIds  直屬下線會員 id
     * @return array{weighted_total: string, vendors: array<int, array{vendor_id: int, game_type_id: int, raw: string, weighted: string, counted: int}>}
     */
    public function collect(GgrVendorSetting $setting, array $memberIds, Carbon $utcStart, Carbon $utcEnd): array
    {
        $configs = $this->buildConfigs($setting);

        if ($memberIds === [] || $configs === []) {
            return ['weighted_total' => '0', 'vendors' => []];
        }

        $total = '0';
        $vendors = [];

        TotalBetRecord::query()
            ->join('games as g', 'g.id', '=', 'total_bet_records.game_id')
            ->whereIn('total_bet_records.member_id', $memberIds)
            ->where('total_bet_records.payout_time', '>=', $utcStart)
            ->where('total_bet_records.payout_time', '<', $utcEnd)
            ->select([
                'total_bet_records.id',
                'total_bet_records.vendor_id',
                'total_bet_records.game_id',
                'total_bet_records.win_lose',
                'g.type_id',
            ])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($configs, &$total, &$vendors): void {
                foreach ($rows as $row) {
                    $key = $row->vendor_id . ':' . $row->type_id;
                    $config = $configs[$key] ?? null;

                    // 查無設定的組合不計算（4-12 安全網）；組合停用不參與（4-11）
                    if ($config === null || !$config['enabled']) {
                        continue;
                    }

                    if (isset($config['excluded'][(int) $row->game_id])) {
                        continue;
                    }

                    $weighted = $this->weightOne((string) $row->win_lose, $config['tiers']);
                    if ($weighted === null) {
                        continue;
                    }

                    $vendors[$key] ??= [
                        'vendor_id' => (int) $row->vendor_id,
                        'game_type_id' => (int) $row->type_id,
                        'raw' => '0',
                        'weighted' => '0',
                        'counted' => 0,
                    ];
                    $vendors[$key]['raw'] = Common::numAdd($vendors[$key]['raw'], Common::numMul($row->win_lose, '-1'));
                    $vendors[$key]['weighted'] = Common::numAdd($vendors[$key]['weighted'], $weighted);
                    $vendors[$key]['counted']++;

                    $total = Common::numAdd($total, $weighted);
                }
            }, 'total_bet_records.id', 'id');

        return ['weighted_total' => $total, 'vendors' => array_values($vendors)];
    }

    /**
     * 單筆落階加權；不納入回 null。
     *
     * @param  array<int, array{threshold: string, min_winlose: string, max_ggr: string, weight_rate: string}>  $tiers  threshold 由高至低
     */
    private function weightOne(string $winLose, array $tiers): ?string
    {
        $ggr = Common::numMul($winLose, '-1');
        $absGgr = ltrim($ggr, '-');

        $hit = null;
        foreach ($tiers as $tier) {
            if (Common::numCompare($absGgr, $tier['threshold']) >= 0) {
                $hit = $tier;

                break;
            }
        }

        // 低於最低階：整筆不納入（正負皆然）
        if ($hit === null) {
            return null;
        }

        $weighted = Common::numMul($ggr, Common::numDiv($hit['weight_rate'], '100'));
        $absWeighted = ltrim($weighted, '-');

        // 加權後下限（看絕對值，0=不限制）：未達不計入
        if (Common::numCompare($hit['min_winlose'], '0') > 0
            && Common::numCompare($absWeighted, $hit['min_winlose']) < 0) {
            return null;
        }

        // 加權後上限（看絕對值，0=無上限）：超過截斷、保留正負號
        if (Common::numCompare($hit['max_ggr'], '0') > 0
            && Common::numCompare($absWeighted, $hit['max_ggr']) > 0) {
            return Common::numCompare($weighted, '0') < 0
                ? Common::numMul($hit['max_ggr'], '-1')
                : (string) $hit['max_ggr'];
        }

        return $weighted;
    }

    /**
     * 明細列組裝為結算設定（降維 vendor_id × game_type_id）。
     * excluded / status 存於 tier=1 列（同組合各階共用）。
     *
     * @return array<string, array{enabled: bool, excluded: array<int, true>, tiers: array<int, array{threshold: string, min_winlose: string, max_ggr: string, weight_rate: string}>}>
     */
    private function buildConfigs(GgrVendorSetting $setting): array
    {
        $configs = [];

        // 先依 vendor_info_id 排序再分組：同 vendor × game_type 多組時「取第一組」
        // 才是確定性的（details 關聯無 orderBy，否則採用哪組取決於 DB 回傳順序）
        $grouped = $setting->details->sortBy('vendor_info_id')->groupBy(
            fn ($row) => $row->vendor_id . ':' . $row->game_type_id . ':' . $row->vendor_info_id
        );

        foreach ($grouped as $groupKey => $rows) {
            $rows = $rows->sortBy('tier')->values();
            $first = $rows->first();
            $key = $first->vendor_id . ':' . $first->game_type_id;

            // 降維防禦：同 vendor × game_type 出現多個 vendor_info 設定組時取
            // vendor_info_id 最小的一組（total_bet_records 無 vendor_info_id 可對應；
            // dev 實測套 GGR 過濾後為 0 組，出現即屬設定資料異常）
            if (isset($configs[$key])) {
                Log::warning('GGR BetCollector: 同 vendor×game_type 有多組 vendor_info 設定，僅採用第一組', [
                    'setting_id' => $setting->id,
                    'duplicated_group' => $groupKey,
                ]);

                continue;
            }

            $configs[$key] = [
                'enabled' => (int) $first->status === 1,
                'excluded' => array_fill_keys(array_map(intval(...), $first->excluded_game_ids ?? []), true),
                'tiers' => $rows
                    ->sort(fn ($a, $b) => Common::numCompare($b->threshold_amount, $a->threshold_amount))
                    ->map(fn ($row) => [
                        'threshold' => (string) $row->threshold_amount,
                        'min_winlose' => (string) $row->min_winlose,
                        'max_ggr' => (string) $row->max_ggr,
                        'weight_rate' => (string) $row->weight_rate,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $configs;
    }
}
