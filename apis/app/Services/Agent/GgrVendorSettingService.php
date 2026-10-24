<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\StatusEnum;
use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Game;
use App\Models\GameType;
use App\Models\GgrVendorSetting;
use App\Models\GgrVendorSettingDetail;
use App\Models\VendorInfo;
use App\Services\Agent\Traits\ResolvesGgrGeneralAgent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 推廣代理 GGR 遊戲商設置（Agent 面向 CRUD，4123）。
 *
 * 一份設置＝主表一筆＋明細多列（vendor_info × game_type × 階層）。
 * 4121 方案以主表 id 為引用契約：無 delete（停用靠 status）、明細採
 * transaction 內全量替換（明細 id 不對外引用）。
 *
 * 本功能僅總代使用，且各總代的設置彼此不互見：一律以操作者的
 * general_agent_id 為可見性軸過濾（list / view / update 三處都要帶），
 * 取不到總代身分即拒絕，避免寫出無歸屬的孤兒資料。
 *
 * 可設定的遊戲商範圍＝「該總代實際開通的」（2026-08-31 工單資料源定案，
 * 對齊舊端點 /api/agentVendor/list）：沿 hierarchy 由近到遠找第一個在
 * agent_vendors 有設定的層級（自己優先、含 Admin）作為繼承來源，再濾掉
 * 停用的 av/v；vendor_infos 恆為啟用且排除 EVENT_V2。vendor-options 下拉
 * 與寫入複驗同源，防繞過前端。
 */
class GgrVendorSettingService
{
    use ResolvesGgrGeneralAgent;

    /** 明細分批 insert 的批量（前端每次全量送出所有支援組合，可達數百列） */
    private const int INSERT_CHUNK = 200;

    public function paginate(Agent $agent, array $filters): LengthAwarePaginator
    {
        return GgrVendorSetting::query()
            ->with(['creator:id,username', 'updater:id,username'])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->when(!empty($filters['name']), fn ($q) => $q->where('name', 'like', '%' . $filters['name'] . '%'))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page']);
    }

    /** 檢視單筆（非本總代或查無皆拋 1010）：主表欄位＋依組合聚合的巢狀明細（含顯示名稱）。 */
    public function find(Agent $agent, int $id): array
    {
        $setting = GgrVendorSetting::query()
            ->with(['creator:id,username', 'updater:id,username'])
            ->where('general_agent_id', $this->resolveGeneralAgentId($agent))
            ->where('id', $id)
            ->first();

        if (!$setting) {
            throw new ApiException(__('error_message.1010'));
        }

        $rows = GgrVendorSettingDetail::query()
            ->where('setting_id', $setting->id)
            ->orderBy('vendor_info_id')
            ->orderBy('game_type_id')
            ->orderBy('tier')
            ->get();

        $vendorInfoNames = VendorInfo::query()
            ->whereIn('id', $rows->pluck('vendor_info_id')->unique())
            ->pluck('name', 'id');
        $gameTypeNames = GameType::query()
            ->whereIn('id', $rows->pluck('game_type_id')->unique())
            ->pluck('name', 'id');

        $details = $rows
            ->groupBy(fn ($row) => $row->vendor_info_id . '-' . $row->game_type_id)
            ->map(function (Collection $group) use ($vendorInfoNames, $gameTypeNames) {
                /** @var GgrVendorSettingDetail $first 排除遊戲與開關為同組合共用，存於 tier=1 列 */
                $first = $group->first();

                return [
                    'vendor_id' => $first->vendor_id,
                    'vendor_info_id' => $first->vendor_info_id,
                    'vendor_info_name' => $vendorInfoNames[$first->vendor_info_id] ?? '',
                    'game_type_id' => $first->game_type_id,
                    'game_type_name' => $gameTypeNames[$first->game_type_id] ?? '',
                    'excluded_game_ids' => $first->excluded_game_ids ?? [],
                    'status' => $first->status,
                    'tiers' => $group->map(fn ($row) => [
                        'threshold_amount' => $row->threshold_amount,
                        'min_winlose' => $row->min_winlose,
                        'max_ggr' => $row->max_ggr,
                        'weight_rate' => $row->weight_rate,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'id' => $setting->id,
            'name' => $setting->name,
            'status' => $setting->status,
            'created_by' => $setting->creator?->username,
            'updated_by' => $setting->updater?->username,
            'created_at' => $setting->created_at?->toBackendTime()->toDateTimeString(),
            'updated_at' => $setting->updated_at?->toBackendTime()->toDateTimeString(),
            'details' => $details,
        ];
    }

    public function create(Agent $agent, array $data): GgrVendorSetting
    {
        $generalAgentId = $this->resolveGeneralAgentId($agent);
        $this->assertDetailsSupported($agent, $data['details']);

        return DB::transaction(function () use ($agent, $generalAgentId, $data) {
            $setting = GgrVendorSetting::create([
                'general_agent_id' => $generalAgentId,
                'name' => $data['name'],
                'status' => $data['status'],
                'created_by' => $agent->id,
                'admin' => $agent->id,
            ]);

            $this->insertDetails($setting, $agent, $data['details']);

            return $setting;
        });
    }

    /** 更新主表（非本總代或查無皆拋 1010）並全量替換明細。 */
    public function update(Agent $agent, int $id, array $data): GgrVendorSetting
    {
        $generalAgentId = $this->resolveGeneralAgentId($agent);
        $this->assertDetailsSupported($agent, $data['details']);

        return DB::transaction(function () use ($agent, $generalAgentId, $id, $data) {
            $setting = GgrVendorSetting::query()
                ->where('general_agent_id', $generalAgentId)
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$setting) {
                throw new ApiException(__('error_message.1010'));
            }

            $setting->fill([
                'name' => $data['name'],
                'status' => $data['status'],
                'admin' => $agent->id,
            ]);
            // 只改明細時主表欄位可能原封不動，Eloquent 便不會下 UPDATE、
            // updated_at 也就停在上一次改名／改狀態的時間；此處明確推進。
            $setting->updated_at = now();
            $setting->save();

            GgrVendorSettingDetail::query()->where('setting_id', $setting->id)->delete();
            $this->insertDetails($setting, $agent, $data['details']);

            return $setting;
        });
    }

    /** 彈窗資料源：總代支援的 vendor_info × game_type 組合與資料驅動的類型 tabs。 */
    public function vendorOptions(Agent $agent): array
    {
        [$gameTypes, $items] = $this->supportedCombos($agent);

        return [
            'game_types' => $gameTypes,
            'items' => $items,
        ];
    }

    /** 排除遊戲下拉：該 vendor × game_type 的遊戲清單（vendor 須在總代支援範圍）。 */
    public function gameOptions(Agent $agent, int $vendorId, int $gameTypeId): array
    {
        $supported = $this->supportedVendorRows($agent);
        if (!$supported->contains(fn ($row) => $row->vendor_id === $vendorId)) {
            throw new ApiException(__('error_message.ggr_setting.vendor_not_supported'));
        }

        return Game::query()
            ->where('vendor_id', $vendorId)
            ->where('type_id', $gameTypeId)
            ->where('status', StatusEnum::ENABLE->value)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($game) => ['id' => $game->id, 'name' => $game->name])
            ->all();
    }

    /**
     * 寫入前複驗（與 vendor-options 同源，防繞過前端）：
     * vendor_info × game_type 組合須在總代支援範圍內且 vendor_id 相符、
     * 排除遊戲須屬於該 vendor × game_type。
     * 排除遊戲不篩 status——已停用遊戲留在既有設定裡編輯不應被擋。
     */
    private function assertDetailsSupported(Agent $agent, array $details): void
    {
        $supported = $this->supportedComboIndex($agent);

        /** @var array<string, array<int, true>> $excludedByCombo vendor_id-game_type_id => 排除遊戲 id 集合 */
        $excludedByCombo = [];

        foreach ($details as $detail) {
            $vendorId = (int) $detail['vendor_id'];
            $gameTypeId = (int) $detail['game_type_id'];

            if (($supported[$detail['vendor_info_id'] . '-' . $gameTypeId] ?? null) !== $vendorId) {
                throw new ApiException(__('error_message.ggr_setting.vendor_not_supported'));
            }

            foreach ($detail['excluded_game_ids'] ?? [] as $gameId) {
                $excludedByCombo[$vendorId . '-' . $gameTypeId][(int) $gameId] = true;
            }
        }

        $this->assertExcludedGamesBelongToCombos($excludedByCombo);
    }

    /**
     * 排除遊戲歸屬複驗：所有組合的遊戲一次撈回再逐組比對，取代逐明細各發一次
     * count 查詢（前端會把全部支援組合一次送出，明細數可達數百列）。
     *
     * @param  array<string, array<int, true>>  $excludedByCombo  vendor_id-game_type_id => 遊戲 id 集合
     */
    private function assertExcludedGamesBelongToCombos(array $excludedByCombo): void
    {
        if ($excludedByCombo === []) {
            return;
        }

        $gameIds = [];
        foreach ($excludedByCombo as $ids) {
            $gameIds = array_merge($gameIds, array_keys($ids));
        }

        $comboByGameId = Game::query()
            ->whereIn('id', array_unique($gameIds))
            ->get(['id', 'vendor_id', 'type_id'])
            ->mapWithKeys(fn (Game $game) => [$game->id => $game->vendor_id . '-' . $game->type_id])
            ->all();

        foreach ($excludedByCombo as $comboKey => $ids) {
            foreach (array_keys($ids) as $gameId) {
                if (($comboByGameId[$gameId] ?? null) !== $comboKey) {
                    throw new ApiException(__('error_message.ggr_setting.game_not_in_vendor'));
                }
            }
        }
    }

    /** 巢狀明細攤平落庫：tier 依列序 1-based；排除遊戲/開關僅存 tier=1 列。 */
    private function insertDetails(GgrVendorSetting $setting, Agent $agent, array $details): void
    {
        $now = now();
        $rows = [];

        foreach ($details as $detail) {
            foreach (array_values($detail['tiers']) as $index => $tier) {
                $isFirstTier = $index === 0;
                $excluded = $isFirstTier ? ($detail['excluded_game_ids'] ?? null) : null;

                $rows[] = [
                    'setting_id' => $setting->id,
                    'vendor_id' => $detail['vendor_id'],
                    'vendor_info_id' => $detail['vendor_info_id'],
                    'game_type_id' => $detail['game_type_id'],
                    'tier' => $index + 1,
                    // 批量 insert 繞過 model cast，JSON 欄位需自行編碼
                    'excluded_game_ids' => $excluded === null ? null : json_encode(array_values($excluded)),
                    'status' => $isFirstTier ? $detail['status'] : StatusEnum::ENABLE->value,
                    'threshold_amount' => $tier['threshold_amount'],
                    'min_winlose' => $tier['min_winlose'],
                    'max_ggr' => $tier['max_ggr'],
                    'weight_rate' => $tier['weight_rate'],
                    'admin' => $agent->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            GgrVendorSettingDetail::query()->insert($chunk);
        }
    }

    /**
     * 總代支援的 vendor_info × game_type 組合，與這些組合實際用到的啟用中類型。
     * vendor-options 下拉與寫入複驗共用同一份，兩邊的判定不會漂移。
     *
     * @return array{0: list<array{id: int, name: string}>, 1: list<array{vendor_id: int, vendor_info_id: int, vendor_info_name: string, game_type_id: int}>}
     */
    private function supportedCombos(Agent $agent): array
    {
        $supported = $this->supportedVendorRows($agent);

        $typeIdsByVendor = Game::query()
            ->whereIn('vendor_id', $supported->pluck('vendor_id')->unique())
            ->where('status', StatusEnum::ENABLE->value)
            ->distinct()
            ->get(['vendor_id', 'type_id'])
            ->groupBy('vendor_id')
            ->map(fn ($rows) => $rows->pluck('type_id')->all());

        // 以 vendor_info_id-game_type_id 為鍵去重：這組合是 4123 明細的唯一鍵
        // （StoreRequest 與 DB unique 都以此為準），來源若重複會讓畫面出現重複列、
        // 送出必被擋。vendor 已取權威值，此處為第二道保險
        $items = [];
        foreach ($supported as $row) {
            foreach ($typeIdsByVendor->get($row->vendor_id, []) as $typeId) {
                $items[$row->vendor_info_id . '-' . $typeId] ??= [
                    'vendor_id' => $row->vendor_id,
                    'vendor_info_id' => $row->vendor_info_id,
                    'vendor_info_name' => $row->vendor_info_name,
                    'game_type_id' => $typeId,
                ];
            }
        }
        $items = array_values($items);

        $gameTypes = GameType::query()
            ->whereIn('id', collect($items)->pluck('game_type_id')->unique())
            ->where('status', StatusEnum::ENABLE->value)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($type) => ['id' => $type->id, 'name' => $type->name])
            ->all();

        $activeTypeIds = array_column($gameTypes, 'id');
        $items = array_values(array_filter($items, fn ($item) => in_array($item['game_type_id'], $activeTypeIds, true)));

        return [$gameTypes, $items];
    }

    /**
     * 支援組合的查表索引，供寫入複驗 O(1) 比對。
     *
     * @return array<string, int> "vendor_info_id-game_type_id" => vendor_id
     */
    private function supportedComboIndex(Agent $agent): array
    {
        [, $items] = $this->supportedCombos($agent);

        $index = [];
        foreach ($items as $item) {
            $index[$item['vendor_info_id'] . '-' . $item['game_type_id']] = $item['vendor_id'];
        }

        return $index;
    }

    /**
     * 該總代實際開通的遊戲商集合（對齊舊端點 agentVendorsTreeSearch 語意，
     * 2026-08-31 工單資料源定案）：沿 hierarchy 由近到遠找第一個在
     * agent_vendors 有設定的層級（自己優先、含 Admin），該層即繼承來源。
     *
     * 與先前「vendor_customized_items 同群組聯集」的差異（dev 以 gffToro 實測）：
     * 聯集會把全站同 vcg 群組任何代理開通的都算進來（112 個 vendor），
     * 本語意只回該總代線上實際開通的（53 個）；vci 不再當白名單。
     */
    private function supportedVendorRows(Agent $agent): Collection
    {
        foreach ($agent->hierarchy_array as $agentId) {
            // 「有沒有設定」看未濾 av/v 狀態的列數：某層存在設定但全停用時
            // 沿用該層（濾完為空），不越過它往上繼承——與舊端點
            // 「先定繼承層、回 status 欄位由前端過濾」的行為一致
            // vendor 一律以 vendor_infos.vendor_id 為權威，不用 av.vendor_id：
            // agent_vendors 存在「同一 vendor_info 掛到不同 vendor」的髒資料
            // （dev 實測 33 組；例：vendor_info 269 tiger9(pgssw) 同時掛 vendor 20/91），
            // 信任 av.vendor_id 會讓同一 vendor_info 回傳多列 → 4123 建立頁同一分頁
            // 出現重複遊戲商、送出時撞組合唯一驗證（QA 2026-09-04 回報），
            // 且會用錯誤的 vendor_id 撈排除遊戲清單
            $rows = DB::table('agent_vendors as av')
                ->join('vendor_infos as vi', 'vi.id', '=', 'av.vendor_info_id')
                ->join('vendors as v', 'v.id', '=', 'vi.vendor_id')
                ->where('av.agent_id', $agentId)
                ->where('vi.status', StatusEnum::ENABLE->value)
                ->where('vi.type', '!=', VendorInfoTypeEnum::EVENT_V2->value)
                ->distinct()
                ->orderBy('v.id')
                ->orderBy('vi.id')
                ->get([
                    'vi.id as vendor_info_id',
                    'vi.name as vendor_info_name',
                    'v.id as vendor_id',
                    'v.name as vendor_name',
                    'av.status as av_status',
                    'v.status as vendor_status',
                ]);

            if ($rows->isNotEmpty()) {
                return $rows
                    ->filter(fn ($row) => (int) $row->av_status === StatusEnum::ENABLE->value
                        && (int) $row->vendor_status === StatusEnum::ENABLE->value)
                    ->values();
            }
        }

        return collect();
    }
}
