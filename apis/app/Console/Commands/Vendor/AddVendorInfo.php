<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfo;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * php artisan vendor:add_vendor_info
 *
 * 流程
 * 1. 新增遊戲商戶 vendor_infos
 * 2. 新增代理與遊戲商關聯 agent_vendors
 * 3. 新增代理關聯的遊戲 agent_games
 * 4. 新增商戶關聯的遊戲 vendor_info_games
 * 5. 顯示在遊戲大廳 vendor_customized_items
 *
 * 如果是VN需特別注意幣值轉換率是 KVND 還是 VND
 * 如果是 KVND 跟跟我方一樣, 故轉換率為 1
 */
class AddVendorInfo extends Command
{
    protected $signature = 'vendor:add_vendor_info';

    protected $description = '在測試環境配置代理的遊戲商戶資訊';

    // protected array $agentOptions = [
    //     '4249 - TP (TPower)',
    //     '59562 - VW (VWorld)',
    //     '100074 - VN (Vietwin88)',
    // ];

    protected array $currencyOptions = [
        'MYR',
        'VND',
        'IDR',
    ];

    protected array $rateOptions = [
        '1:1',
        '1:1000',
    ];

    private array $agentIds;

    /**
     * Execute the console command.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        $isTest = $this->confirm('是否啟用測試模式（不會寫入資料）？', true);

        if (!in_array(strtolower((string) config('database.default')), ['mysql', 'dev', 'testing'], true)) {
            // 確認要在正式環境執行
            $confirm = $this->confirm('目前資料庫連線為 ' . config('database.default') . '，確定要繼續？', false);
            if (!$confirm) {
                $this->info('腳本已終止');

                return;
            }
        }

        $vendorId = (int) $this->ask('請輸入遊戲商ID');
        $agentId = (int) $this->ask('請輸入代理商ID');
        $this->agentIds = [1, $agentId];

        try {
            // 檢查代理商是否存在
            $this->checkAgent($agentId);

            // 檢查遊戲商是否存在
            $vendor = $this->checkVendor($vendorId);

            $games = $this->queryGames($vendor);

            // ── Phase 1：收集所有互動輸入，transaction 開啟前完成 ──
            // 缺少遊戲時：可選擇從父站點複製，或在缺少遊戲下先配置商戶

            $gamesDecision = $this->askGamesDecision($vendor, $vendorId, $games);
            if ($gamesDecision === null) {
                $this->info('腳本已終止');

                return;
            }

            $vendorInfoInput = $this->collectVendorInfoInput($vendor);
            if ($vendorInfoInput === null) {
                return;
            }

            // ── Phase 2：所有寫入操作集中在 transaction 內，不含任何互動問題 ──

            DB::beginTransaction();

            $games = $this->applyGamesDecision($vendor, $games, $gamesDecision);

            // 新增遊戲商戶 vendor_infos
            if ($vendorInfoInput['skipUpdate'] ?? false) {
                $vendorInfo = VendorInfo::where('vendor_id', $vendor->id)->firstOrFail();
            } else {
                $vendorInfo = $this->saveVendorInfo($vendor, $vendorInfoInput);
            }

            // 新增代理與遊戲商關聯 agent_vendors
            $this->addAgentVendor($vendorInfo);

            // 新增代理關聯的遊戲 agent_games
            $this->addAgentGames($games);

            // 新增商戶關聯的遊戲 vendor_info_games
            $this->addVendorInfoGames($vendorInfo, $games);

            // 顯示在遊戲大廳
            $this->addVendorCustomizedItem($vendor);

            if ($isTest) {
                DB::rollBack();
                $this->info('腳本已執行完成，所有資料已回滾，未寫入資料庫。');
            } else {
                DB::commit();
                $this->info('腳本已執行完成');
            }
        } catch (Exception $e) {
            DB::rollBack();
            $this->error($e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 1：互動輸入收集（不開 transaction）
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 詢問遊戲處理方式，回傳決策陣列；使用者選擇終止時回傳 null。
     *
     * @return array{action: string}|null
     */
    private function askGamesDecision(Vendor $vendor, int $vendorId, Collection $games): ?array
    {
        if (!$games->isEmpty()) {
            return ['action' => 'use_existing'];
        }

        $copyGames = $this->confirm('遊戲商ID: ' . $vendorId . ' 沒有遊戲在 games 表中，是否從 TPower / VWorld 複製遊戲？', true);
        if (!$copyGames) {
            $continue = $this->confirm('是否在缺少遊戲的下先配置遊戲商戶 VendorInfo？', false);

            return $continue ? ['action' => 'skip_games'] : null;
        }

        return ['action' => 'copy_games'];
    }

    /**
     * 收集 VendorInfo 所需的所有欄位輸入；使用者選擇跳過時回傳 null。
     *
     * @return array{remoteVendorInfo: VendorInfo|null, skipUpdate: bool, accountPrefix: string, currency: string, rate: int}|null
     */
    private function collectVendorInfoInput(Vendor $vendor): ?array
    {
        $this->info('收集 VendorInfo 設定');

        // 從遠端站點取得 info key 結構（唯讀，transaction 外執行）
        $remoteVendorInfo = null;
        foreach (['TPower', 'VWorld'] as $connection) {
            $remoteVendorInfo = VendorInfo::on($connection)->where('vendor_id', $vendor->id)->first();
            if ($remoteVendorInfo) {
                break;
            }
        }

        if (!$remoteVendorInfo) {
            $this->warn('TPower 與 VWorld 的 vendor_infos 表中都找不到 vendor_id=' . $vendor->id . ' 的數據。');
            $this->warn('建立的 VendorInfo 將配置預設值。');
        }

        $localVendorInfo = VendorInfo::where('vendor_id', $vendor->id)->first();

        if ($localVendorInfo) {
            $update = $this->confirm('VendorInfo vendor_id=' . $vendor->id . ' 的數據已存在，是否要更新？');
            if (!$update) {
                $this->warn('Skip addVendorInfo vendor_id=' . $vendor->id);

                return ['skipUpdate' => true];
            }
        } else {
            $this->info('新增 VendorInfo vendor_id=' . $vendor->id . ' 的數據。');
        }

        // 遊戲帳號前綴
        $prefix = trim((string) $this->ask('請設定玩家遊戲帳號前綴 (prefix) 留空使用 $vendor.code'));
        $accountPrefix = $this->resolveAccountPrefix($vendor, $prefix);

        // 使用幣別
        $currency = $this->choice('請選擇幣別', $this->currencyOptions, 0);

        // 貨值匯率
        $selectRate = $this->choice('請選擇幣值轉換率', $this->rateOptions, 0);
        $rate = ($selectRate === '1:1000') ? 1000 : 1;

        return [
            'remoteVendorInfo' => $remoteVendorInfo,
            'accountPrefix' => $accountPrefix,
            'currency' => $currency,
            'rate' => $rate,
        ];
    }

    private function resolveAccountPrefix(Vendor $vendor, string $prefix): string
    {
        if ($vendor->id === VendorIdEnum::JOKER_BACCARAT_SW->value) {
            return '';
        }

        return strtolower($prefix === '' ? $vendor->code : $prefix);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Phase 2：寫入操作（transaction 內，不含任何互動問題）
    // ─────────────────────────────────────────────────────────────────────────

    private function applyGamesDecision(Vendor $vendor, Collection $games, array $decision): Collection
    {
        if ($decision['action'] !== 'copy_games') {
            return $games;
        }

        $hasGames = $this->tryCopyGamesFromParentSources($vendor);
        if (!$hasGames) {
            $this->warn('TPower / VWorld 的 games 表中都沒有遊戲資料，需自行匯入遊戲。');
        }

        return $this->queryGames($vendor);
    }

    private function saveVendorInfo(Vendor $vendor, array $input): VendorInfo
    {
        $this->info('addVendorInfo Begin');

        $remoteVendorInfo = $input['remoteVendorInfo'];

        // info 經 model cast/accessor 已是陣列；保留所有 key 但清空 value，再轉回 JSON 字串
        $infoArray = $remoteVendorInfo?->info ?? [];
        $keys = array_keys($infoArray);
        $emptyInfo = array_fill_keys($keys, '');
        $infoPayload = empty($emptyInfo) ? new \stdClass : $emptyInfo;
        $infoStr = json_encode($infoPayload, JSON_UNESCAPED_UNICODE) ?: '{}';

        // 寫入 VendorInfo
        $vendorInfo = VendorInfo::updateOrCreate(
            ['vendor_id' => $vendor->id],
            [
                'name' => $vendor->name,
                'type' => $remoteVendorInfo->type ?? 1, // 遊戲商環境，0:測試，1:正式
                'currency' => $input['currency'],
                'rate' => $input['rate'],
                'transfer_min_amount' => $remoteVendorInfo->transfer_min_amount ?? 0.01000,
                'info' => $infoStr,
                'prepared_account_number' => 1,
                'account_prefix' => $input['accountPrefix'],
                'cost' => $remoteVendorInfo->cost ?? 0.0000,
                'remark' => '',
                'status' => 0, // 配置完 info 再手動開啟然後檢查遊戲帳號是否建立
                'rolling_chip_status' => 0,
                'admin' => 0,
            ]
        );

        $this->info('addVendorInfo Done');

        return $vendorInfo;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 檢查與查詢（唯讀，不受 transaction 影響）
    // ─────────────────────────────────────────────────────────────────────────

    private function checkAgent(int $agentId): void
    {
        $this->line('檢查 agent');

        if (!Agent::query()->find($agentId)) {
            throw new Exception('agent 不存在');
        }
    }

    private function checkVendor(int $vendorId): Vendor
    {
        $this->line('檢查 vendor');

        $vendor = Vendor::query()->find($vendorId);

        if (!$vendor) {
            $this->warn('請將遊戲商配置在 database/data/vendors.php 後升級');
            throw new Exception('vendor 不存在');
        }

        if ($vendor->status != 1) {
            $sql = sprintf('UPDATE `vendor` SET `status` = 1 WHERE `id` = %s', $vendorId);
            $this->warn('請執行 SQL: ' . $sql);
            throw new Exception('vendor 狀態非啟用中');
        }

        // 檢查遊戲商 accounts 表是否存在
        $accountTable = $vendor->tname . '_accounts';
        if (!Schema::hasTable($accountTable)) {
            throw new Exception('遊戲帳號表 ' . $accountTable . ' 不存在');
        }

        // 檢查遊戲商 bet_records 表是否存在
        $betRecordTable = $vendor->tname . '_bet_records';
        if (!Schema::hasTable($betRecordTable)) {
            throw new Exception('遊戲投注紀錄表 ' . $betRecordTable . ' 不存在');
        }

        return $vendor;
    }

    private function queryGames(Vendor $vendor): Collection
    {
        return Game::query()
            ->where('vendor_id', $vendor->id)
            // ->where('status', 1)
            ->orderBy('id')
            ->get();
    }

    private function tryCopyGamesFromParentSources(Vendor $vendor): bool
    {
        foreach (['TPower', 'VWorld'] as $connection) {
            if ($this->copyGameFromParent($vendor, $connection)) {
                return true;
            }
        }

        return false;
    }

    private function copyGameFromParent(Vendor $vendor, string $connection): bool
    {
        $games = Game::on($connection)
            ->where('vendor_id', $vendor->id)
            ->get();

        if ($games->isEmpty()) {
            return false;
        }

        // 本地可能已經存在相同 vendor 的遊戲
        $localGameTypes = Game::query()
            ->where('vendor_id', $vendor->id)
            ->pluck('game_type')
            ->toArray();

        // 用 $games 比對 $localGameTypes，留下未重複的
        // 如果 game_type 已存在本地就排除
        $games = $games->reject(fn ($game) => in_array($game->game_type, $localGameTypes));

        $gameData = $games->map(fn ($game) => [
            'vendor_id' => $vendor->id,
            'type_id' => $game->type_id,
            'join_bet_event' => $game->join_bet_event,
            'join_win_event' => $game->join_win_event,
            'game_type' => $game->game_type,
            'name' => $game->name,
            'lottery_pool' => $game->lottery_pool,
            'image' => $game->image,
            'is_new' => $game->is_new,
            'is_hot' => $game->is_hot,
            'open_window' => $game->open_window,
            'status' => $game->status,
            'rtp' => $game->rtp,
            'sort' => $game->sort,
            'info' => $game->info,
            'remark' => $game->remark,
            'memo' => $game->memo,
            'admin' => 0,
            'valid_bet_rate' => $game->valid_bet_rate,
            'calc_flow_limit' => $game->calc_flow_limit,
        ])->toArray();

        // 批量插入遊戲到 games 表中
        if (!empty($gameData)) {
            DB::table('games')->insert($gameData);
            $this->info('共計 ' . count($gameData) . ' 個遊戲已從 ' . $connection . ' 複製到 ' . config('database.default') . ' 的 games 表中。');
        } else {
            $this->warn('沒有遊戲需要從 ' . $connection . ' 複製到 ' . config('database.default') . ' 的 games 表中。');
        }

        return true;
    }

    private function addAgentVendor(VendorInfo $vendorInfo): void
    {
        $this->info('addAgentVendor Begin');

        foreach ($this->agentIds as $agentId) {
            $exists = AgentVendor::where([
                'agent_id' => $agentId,
                'vendor_id' => $vendorInfo->vendor_id,
                'vendor_info_id' => $vendorInfo->id,
            ])->exists();

            if ($exists) {
                $this->warn('Skip addAgentVendor agent_id=' . $agentId . ' vendor_id=' . $vendorInfo->vendor_id);

                continue;
            }

            AgentVendor::create([
                'agent_id' => $agentId,
                'vendor_id' => $vendorInfo->vendor_id,
                'vendor_info_id' => $vendorInfo->id,
                'status' => ($agentId == 1) ? 0 : 1,
                'self_status' => ($agentId == 1) ? 0 : 1, // admin 不用開啟
                'backend_show' => 1,
                'admin' => 1,
            ]);
        }

        $this->info('addAgentVendor Done');
    }

    private function addAgentGames(Collection $games): void
    {
        if ($games->isEmpty()) {
            $this->info('缺少遊戲, 略過 addAgentGames');

            return;
        }

        $this->info('addAgentGames Begin');

        // 依序為所有代理關聯遊戲
        foreach ($this->agentIds as $agentId) {
            // 查詢已存在的 game_id
            $existingGameIds = DB::table('agent_games')
                ->where('agent_id', $agentId)
                ->pluck('game_id')
                ->toArray();

            // 過濾已存在的遊戲，留下需要新增到 agent_games 的遊戲
            $agentGames = $games->reject(fn ($game) => in_array($game->id, $existingGameIds))
                ->map(fn ($game) => [
                    'agent_id' => $agentId,
                    'game_id' => $game->id,
                    'status' => 1,
                    'admin' => 0,
                ])->values()->all();

            if (!empty($agentGames)) {
                DB::table('agent_games')->insert($agentGames);
                $this->warn('已成功新增代理 agent_id=' . $agentId . ' 關聯的遊戲。');
            } else {
                $this->warn('代理 agent_id=' . $agentId . ' 的遊戲皆已存在，無需新增。');
            }

            $this->info('addAgentGames Done');
        }
    }

    private function addVendorInfoGames(VendorInfo $vendorInfo, Collection $games): void
    {
        if ($games->isEmpty()) {
            $this->info('缺少遊戲, 略過 addVendorInfoGames');

            return;
        }

        $this->info('addVendorInfoGames Begin');

        // 查詢已存在的 game_id
        $existingGameIds = DB::table('vendor_info_games')
            ->where('vendor_info_id', $vendorInfo->id)
            ->pluck('game_id')
            ->toArray();

        // 過濾已存在的遊戲，留下需要新增到 vendor_info_games 的遊戲
        $vendorInfoGames = $games->reject(fn ($game) => in_array($game->id, $existingGameIds))
            ->map(fn ($game) => [
                'vendor_info_id' => $vendorInfo->id,
                'game_id' => $game->id,
                'join_bet_event' => $game->join_bet_event,
                'join_win_event' => $game->join_win_event,
                'is_new' => $game->is_new,
                'is_hot' => $game->is_hot,
                'sort' => $game->sort,
                'valid_bet_rate' => $game->valid_bet_rate,
                'calc_flow_limit' => $game->calc_flow_limit,
                'status' => 1,
                'admin' => 0,
            ])->values()->all();

        if (!empty($vendorInfoGames)) {
            DB::table('vendor_info_games')->insert($vendorInfoGames);
            $this->warn('已成功新增遊戲商戶 vendor_info_id=' . $vendorInfo->id . ' 關聯的遊戲。');
        } else {
            $this->warn('所有遊戲皆已存在於 vendor_info_games，無需新增。');
        }
        // dd($vendorInfoGames, $vendorInfo->id);
        $this->info('addVendorInfoGames Done');
    }

    private function addVendorCustomizedItem(Vendor $vendor): void
    {
        $this->info('addVendorCustomizedItem Begin');

        // 依序為所有代理關聯遊戲
        foreach ($this->agentIds as $agentId) {
            // 跳過總代
            if ($agentId == 1) {
                continue;
            }

            try {
                $agent = Agent::findOrFail($agentId);
            } catch (Throwable $e) {
                $this->error('代理ID: ' . $agentId . ' 不存在於 agents 表中。');

                continue;
            }

            // 先檢查是否已存在資料，存在則略過
            $exists = DB::table('vendor_customized_items')->where([
                'agent_group_id' => $agent->vendor_customize_group_id,
                'vendor_id' => $vendor->id,
            ])->exists();

            if ($exists) {
                $this->warn('Skip addVendorCustomizedItem agent_group_id=' . $agent->vendor_customize_group_id . ' vendor_id=' . $vendor->id);

                continue;
            }

            $maxSort = DB::table('vendor_customized_items')
                ->where('agent_group_id', $agent->vendor_customize_group_id)
                ->max('sorts');

            $sorts = is_null($maxSort) ? 1 : min(127, $maxSort + 1);

            DB::table('vendor_customized_items')->insert([
                'agent_group_id' => $agent->vendor_customize_group_id,
                'vendor_id' => $vendor->id,
                'item_type' => 1, // 1: 遊戲商主頁, 2: 開啟遊戲, 3: 遊戲商類型, 4: 內部連結
                'game_id' => 0,
                'type_id' => 0, // 0: 全部遊戲類別, 1: 真人, 2: 老虎機, 3: 棋牌, 4: 彩票遊戲, 5: 捕魚
                'path' => '',
                'name' => $vendor->name,
                'image' => $vendor->image,
                'app_icon' => $vendor->app_icon,
                'is_new' => 0,
                'is_hot' => 0,
                'status' => 0, // 手動開啟
                'sorts' => $sorts,
                'admin' => 0,
            ]);
        }

        $this->info('addVendorCustomizedItem Done');
    }
}
