<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use App\Console\Commands\CashFlow\Concerns\ResolvesMainCurrency;
use App\Console\Commands\CashFlow\Concerns\ResolvesProviderConfigFile;
use App\Enums\General\CacheTtl;
use App\Enums\WithdrawalTypeEnum;
use App\Helpers\Common;
use App\Helpers\StandardBankNameHelper;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use App\Models\Currency;
use App\Models\Language;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CashFlow 同步命令
 *
 * 功能說明：
 *  - 從 `Data/cash_flows.php` 讀取設定，建立 / 更新 CashFlow
 *  - 針對 type = 1（代收）建立對應的 OrderType 結構：
 *      - 父層：渠道（channel，choose_bank 可能為 1）
 *      - 子層：銀行選項（banks，choose_bank 一律 0）
 *  - 針對 type = 3（代付）寫入 banks 等資料
 *
 * 執行模式：
 *  1. 正常模式：實際執行並提交變更到資料庫
 *  2. 乾跑模式（--dry-run）：執行操作但最後回滾，用於預覽變更
 *
 * 使用方式：
 *   php artisan cash-flow:sync --ids=91,92 --currency=MYR
 *
 * 幣別代碼對照：
 *   1  = MYR (馬來西亞令吉)
 *   2  = SGD (新加坡元)
 *   3  = VND (越南盾)
 *   6  = IDR (印尼盾)
 *   9  = THB (泰銖)
 *   10 = USDT (泰達幣)
 *   16 = HKD (港幣)
 *
 * 幣別過濾警示：
 *   當使用 --currency 參數指定幣別時，如果某個 CashFlow 沒有配置該幣別的渠道或銀行，
 *   系統會顯示警告訊息，例如：
 *   ⚠️  FPay (ID: 67) 沒有配置 SGD (新加坡元) 的代收渠道
 *   ⚠️  GMPay (ID: 74) 沒有配置 VND (越南盾) 的代付銀行
 *
 * @knowledge rules/payout-bank-code-mapping
 * @knowledge rules/standard-bank-list
 */
class SyncCashFlowsCommand extends Command
{
    use ResolvesMainCurrency;
    use ResolvesProviderConfigFile;

    /**
     * 命令名稱與簽名
     *
     * 執行模式說明：
     *
     * 1. 正常模式（無參數）
     *    - 實際執行所有操作
     *    - 變更會提交到資料庫（DB::commit）
     *    - 適用：生產環境正式同步
     *
     * 2. 乾跑模式（--dry-run）
     *    - 實際執行所有操作和驗證邏輯
     *    - 所有資料庫操作都會執行（INSERT/UPDATE）
     *    - 最後會回滾所有變更（DB::rollBack）
     *    - 適用：預覽將要執行的操作、確認配置正確
     *
     * 差異對比：
     * ┌──────────┬─────────┬──────────┐
     * │ 項目     │ 正常    │ --dry-run│
     * ├──────────┼─────────┼──────────┤
     * │ 執行操作 │ ✓       │ ✓        │
     * │ 驗證邏輯 │ ✓       │ ✓        │
     * │ 顯示訊息 │ ✓       │ ✓        │
     * │ 提交變更 │ ✓       │ ✗        │
     * │ 回滾變更 │ ✗       │ ✓        │
     * └──────────┴─────────┴──────────┘
     *
     * @var string
     */
    protected $signature = 'cash-flow:sync
                            {--ids= : 指定要處理的 CashFlow ID，多個 ID 用逗號分隔，例如：1,2,3}
                            {--currency= : 指定幣別 ID 或代碼，只建立該幣別的渠道和銀行。例如：VND、SGD、3、2（支援：MYR=1, SGD=2, VND=3, IDR=6, THB=9, USDT=10, HKD=16）}
                            {--rename-key=* : 顯式改鍵 舊key=新key（config key 修正時就地 update order_types.key；OT id 不變、ACF/ACFOT 關聯自動跟上；可多次指定）}
                            {--dry-run : 乾跑模式，預覽操作但不實際寫入資料庫}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '同步 CashFlow 及關聯的 OrderType、Bank 資料（支援 --dry-run 預覽模式、--currency 幣別過濾）';

    /** @var string 資料檔案路徑 */
    protected string $dataFile = 'cash_flows.php';

    /** @var array<string, string> --rename-key 解析結果（舊key => 新key），由 parseRenameKeyOption 填入 */
    private array $keyRenames = [];

    /** @var array CashFlow 預設值 */
    protected array $cashFlowDefaults = [
        'status' => 1,
        'info' => '',
        'admin' => 0,
    ];

    /** @var array OrderType 預設值 */
    protected array $orderTypeDefaults = [
        'type' => 0,
        'info' => null,
        'need_first_name' => 0,
        'sorts' => 0,
        'status' => 1,
        'image_path' => null,
        'admin' => 1,
    ];

    /** @var array<string, int>|null 語言代碼 → ID 對應表（首次使用時載入並快取於記憶體） */
    private ?array $languageMap = null;

    /** @var \App\Services\CashFlow\Bank\BankStandardMappingService|null Phase 2 防再生映射服務（首次使用時解析，服務內部自帶 lookup 記憶化） */
    private ?\App\Services\CashFlow\Bank\BankStandardMappingService $standardMappingService = null;

    private function standardMapping(): \App\Services\CashFlow\Bank\BankStandardMappingService
    {
        return $this->standardMappingService ??= app(\App\Services\CashFlow\Bank\BankStandardMappingService::class);
    }

    /**
     * 代付渠道銀行分組 → WithdrawalTypeEnum 對應表
     *
     * config `withdrawal.channels[]` 下可同時存在 banks / ewallets / crypto 三個分組，
     * 同步時依此 mapping 自動把對應 banks.type 寫入平台銀行（agent_group_id=0）。
     *
     * value=null 代表「跟隨 channel.type」（已由 applyMainCurrencyNormalization 翻轉為
     * BANK 或 FOREIGN_BANK），確保跨幣別環境下傳統銀行寫入正確為 FOREIGN_BANK(4)。
     *
     * @var array<string, WithdrawalTypeEnum|null>
     */
    private const array CHANNEL_BANK_GROUPS = [
        'banks' => null,
        'ewallets' => WithdrawalTypeEnum::GCASH,
        'crypto' => WithdrawalTypeEnum::CRYPTO,
    ];

    // =========================================================================
    // 主流程與參數解析
    // =========================================================================

    /**
     * 執行命令
     *
     * 流程：
     *  1. 解析命令列參數（--ids, --dry-run）
     *  2. 載入資料檔案（預設為 Data/cash_flows.php）
     *  3. 逐筆處理每個 CashFlow 設定
     *  4. 依照執行模式決定是否提交或回滾變更：
     *     - 正常模式：DB::commit() - 提交所有變更
     *     - 乾跑模式：DB::rollBack() - 回滾變更，顯示「乾跑模式」訊息
     *  5. 最後輸出統計資訊
     */
    public function handle(): int
    {
        $this->info('CashFlow 同步命令開始執行...');

        // 解析命令參數
        $isDryRun = $this->option('dry-run');
        $targetCashFlowIds = $this->parseTargetIds();
        $targetCurrencyId = $this->parseTargetCurrency();

        if (!$this->parseRenameKeyOption()) {
            return self::FAILURE;
        }

        if ($targetCashFlowIds !== null) {
            $this->info(sprintf('指定處理的 CashFlow ID: %s', implode(', ', $targetCashFlowIds)));
        }

        if ($targetCurrencyId !== null) {
            $currencyName = $this->getCurrencyName($targetCurrencyId);
            $this->info(sprintf('指定幣別過濾: %s (ID: %d)', $currencyName, $targetCurrencyId));
        }

        if ($isDryRun) {
            $this->warn('⚠️  乾執行模式已啟用，將顯示操作但不實際寫入資料庫');
        }

        // 載入資料來源
        $source = $this->loadSource($targetCashFlowIds, $targetCurrencyId);

        if (empty($source)) {
            $this->error('沒有找到需要處理的 CashFlow 資料');

            return self::FAILURE;
        }

        // 初始化統計資訊
        $stats = [
            'cash_flow_inserted' => 0,
            'cash_flow_updated' => 0,
            'cash_flow_skipped' => 0,
            'order_type_parent_inserted' => 0,
            'order_type_child_inserted' => 0,
            'order_type_updated' => 0,
            'order_type_skipped' => 0,
            'order_type_disabled' => 0,
            'order_type_name_inserted' => 0,
            'order_type_name_updated' => 0,
            'order_type_name_skipped' => 0,
            'bank_inserted' => 0,
            'bank_skipped' => 0,
            'cash_flow_bank_inserted' => 0,
            'cash_flow_bank_skipped' => 0,
        ];

        try {
            DB::beginTransaction();

            // 使用進度條顯示處理進度
            $progressBar = $this->output->createProgressBar(count($source));
            $progressBar->start();

            foreach ($source as $data) {
                if (!$this->isValidCashFlow($data)) {
                    $this->newLine();
                    $this->warn(sprintf('略過無效的 CashFlow 資料: %s', $data['name'] ?? 'Unknown'));
                    $progressBar->advance();

                    continue;
                }

                $this->processCashFlow($data, $stats, $targetCurrencyId);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            if ($isDryRun) {
                $this->warn("\n✓ 乾執行模式: 已回滾所有變更，以上為預覽操作");
                DB::rollBack();
            } else {
                DB::commit();
                $this->info('✓ 所有變更已成功寫入資料庫');
            }

            $this->printStats($stats);
        } catch (\Exception $e) {
            DB::rollBack();
            $this->newLine();
            $this->error(sprintf('發生錯誤: %s', $e->getMessage()));
            $this->line(Common::formatTrace($e));

            return self::FAILURE;
        }

        $this->info('CashFlow 同步命令已執行完成。');

        return self::SUCCESS;
    }

    /**
     * 解析目標 CashFlow ID
     *
     * @return array|null 返回 ID 陣列或 null（處理所有）
     */
    protected function parseTargetIds(): ?array
    {
        $idsOption = $this->option('ids');

        if (!$idsOption) {
            return null;
        }

        // 分割逗號並轉換為整數陣列
        $ids = array_map('intval', explode(',', $idsOption));

        // 過濾掉無效的 ID（小於等於 0 的值）
        $ids = array_filter($ids, fn ($id) => $id > 0);

        return empty($ids) ? null : array_values($ids);
    }

    /**
     * 解析目標幣別
     *
     * @return int|null 返回幣別 ID 或 null（處理所有幣別）
     */
    protected function parseTargetCurrency(): ?int
    {
        $currencyOption = $this->option('currency');

        if (!$currencyOption) {
            return null;
        }

        // 如果是數字，直接使用
        if (is_numeric($currencyOption)) {
            $currencyId = (int) $currencyOption;

            // 驗證幣別是否存在
            if (Currency::find($currencyId)) {
                return $currencyId;
            }

            $this->warn(sprintf('警告: 幣別 ID %d 不存在', $currencyId));

            return null;
        }

        // 否則當作幣別代碼處理
        $currencyCode = strtoupper(trim($currencyOption));
        $currency = Currency::where('code', $currencyCode)->first();

        if ($currency) {
            return $currency->id;
        }

        $this->warn(sprintf('警告: 幣別代碼 %s 不存在', $currencyCode));

        return null;
    }

    /**
     * 取得幣別名稱
     *
     * @param  int  $currencyId  幣別 ID
     * @return string 幣別名稱
     */
    protected function getCurrencyName(int $currencyId): string
    {
        $currency = Currency::find($currencyId);

        return $currency ? sprintf('%s (%s)', $currency->code, $currency->name) : 'Unknown';
    }

    /**
     * 驗證 CashFlow 資料是否有效
     */
    protected function isValidCashFlow(array $data): bool
    {
        return isset($data['id']) && isset($data['name'], $data['type']);
    }

    // =========================================================================
    // CashFlow 同步（單筆處理入口）
    // =========================================================================

    /**
     * 處理單一 CashFlow 及其關聯設定
     *
     * 責任：
     *  - 若 CashFlow 不存在則建立
     *  - 若已存在則更新 info 欄位
     *  - 若為代收 type=1，且有 channels 資料，呼叫 `processDepositChannel()` 建立 OrderType
     *  - 若為代付 type=3，且有 channels 資料，呼叫 `processWithdrawalBanks()` 建立 Bank 關聯
     *
     * @param  array  $data  CashFlow 資料
     * @param  array  &$stats  統計資料
     * @param  int|null  $targetCurrencyId  目標幣別 ID（null 表示處理所有幣別）
     */
    protected function processCashFlow(array $data, array &$stats, ?int $targetCurrencyId = null): void
    {
        // 處理 CashFlow
        $cashFlowId = $data['id'];
        $existingCashFlow = CashFlow::find($cashFlowId);

        if (!$existingCashFlow) {
            // 新增 CashFlow
            $cashFlow = CashFlow::create($this->prepareCashFlowData($data));
            $stats['cash_flow_inserted']++;
            $this->line(sprintf('  ✓ 新增 CashFlow: %s (ID: %d)', $data['name'], $cashFlowId));
        } else {
            // 已存在則同步 name 與 info 欄位（Data 檔為 source of truth）
            $cashFlow = $existingCashFlow;
            $updateData = [];

            if ($existingCashFlow->name !== $data['name']) {
                $updateData['name'] = $data['name'];
            }

            if (isset($data['info'])) {
                $updateData['info'] = $data['info'];
            }

            if (!empty($updateData)) {
                $cashFlow->update($updateData);
                $stats['cash_flow_updated']++;
                $this->line(sprintf('  ✓ 更新 CashFlow: %s (ID: %d)', $data['name'], $cashFlowId));
            } else {
                $stats['cash_flow_skipped']++;
                $this->line(sprintf('  - CashFlow 無變更，略過: %s (ID: %d)', $data['name'], $cashFlowId));
            }
        }

        // 處理關聯的 OrderType（type = 1 代收）
        if ($data['type'] === 1 && !empty($data['channels'])) {
            $this->processDepositChannel($cashFlow, $data['channels'], $stats, $targetCurrencyId);
        }

        // 處理關聯的 Bank（type = 3 代付）
        if ($data['type'] === 3 && !empty($data['channels'])) {
            $this->processWithdrawalBanks($cashFlow, $data['channels'], $stats, $targetCurrencyId);
        }
    }

    /**
     * 準備 CashFlow 資料
     */
    protected function prepareCashFlowData(array $data): array
    {
        // 只移除非 CashFlow 欄位 'channels'（保留其他欄位，如 info）
        if (isset($data['channels'])) {
            unset($data['channels']);
        }

        // 仍保留原本的 defaults 合併行為
        return array_merge($this->cashFlowDefaults, $data);
    }

    // =========================================================================
    // 代收 OrderType 同步（父層渠道／子層銀行選項／多語系名稱）
    // =========================================================================

    /**
     * 驗證 OrderType 定義是否完整
     *
     * 必要欄位：
     *  - currency  : 幣別
     *  - name      : 渠道名稱
     *  - type      : 存款方式 DepositTypeEnum
     *  - key       : 三方渠道代號
     *
     * 若缺少欄位會印出警告並回傳 false。
     */
    protected function isOrderTypeValid(array $channelData): bool
    {
        $checkedFields = ['currency', 'name', 'type', 'key'];

        foreach ($checkedFields as $field) {
            if (!isset($channelData[$field])) {
                $this->warn(sprintf('    警告: OrderType 資料缺少 \'%s\' 欄位，略過', $field));

                return false;
            }
        }

        return true;
    }

    /**
     * 處理代收渠道列表（父層 OrderType）
     *
     * 每個 channel 會：
     *  - 先通過 `isOrderTypeValid()` 檢查必填欄位
     *  - 若指定了 targetCurrencyId，則過濾只處理該幣別的渠道
     *  - 呼叫 `processOrderType()` 建立 / 更新父層 OrderType
     *
     * @param  CashFlow  $cashFlow  金流商物件
     * @param  array  $channels  渠道列表
     * @param  array  &$stats  統計資料
     * @param  int|null  $targetCurrencyId  目標幣別 ID（null 表示處理所有幣別）
     */
    protected function processDepositChannel(CashFlow $cashFlow, array $channels, array &$stats, ?int $targetCurrencyId = null): void
    {
        // 記錄配置中「仍存在」的父層渠道 key（依幣別分組），供處理後下架 config 已移除的 OrderType
        $configuredKeys = [];

        foreach ($channels as $channelData) {
            // 檢查 OrderType 資料完整性
            if (!$this->isOrderTypeValid($channelData)) {
                continue;
            }

            $channelCurrencyId = $this->getCurrencyId($channelData['currency']);

            // 如果指定了幣別過濾，檢查渠道幣別是否匹配
            if ($targetCurrencyId !== null && $channelCurrencyId !== $targetCurrencyId) {
                // 略過不符合的幣別
                continue;
            }

            // 記錄有效且符合幣別過濾的配置 key（在 continue 之後收集，確保與下架 scope 一致）
            if ($channelCurrencyId) {
                $configuredKeys[$channelCurrencyId][] = (string) ($channelData['key'] ?? '');
            }

            // 處理支付渠道 OrderType
            $this->processOrderType($cashFlow, $channelData, $stats);
        }

        // 處理完後，下架 config 已移除的父層 OrderType（連同銀行子項），對稱代付側 disableRemovedBanks。
        // 僅在本方法（channels 非空時才被呼叫）內執行，config 讀取失敗→channels 空→不進本方法→不誤下架。
        $this->disableRemovedOrderTypes($cashFlow, $configuredKeys, $stats, $targetCurrencyId);
    }

    /**
     * 下架 config 已移除的代收父層 OrderType（連同其銀行子項），下架＝status=0（不刪列）。
     *
     * 對稱代付側 {@see disableRemovedBanks}：sync 只處理 cash_flow 層實體（order_types），
     * 不動 agent 層 ACFOT（屬 {@see AddAgentCashFlow} 守備範圍）。會員可見性由
     * {@see \App\Services\OrderTypeService::getOrderTypes} 的 order_types.status=1 過濾把關，
     * 故父層 status=0 即足以對會員隱藏。
     *
     * 保守策略：僅下架「config 已不存在」的渠道，不主動 force 重啟 config 內的渠道，
     * 避免與維運人員手動下架（DB 手動 status=0）打架（此點刻意比代付側 pre-disable-all 保守）。
     *
     * @param  array<int, array<int, string>>  $configuredKeys  currency_id => [父層 key, ...]
     */
    protected function disableRemovedOrderTypes(CashFlow $cashFlow, array $configuredKeys, array &$stats, ?int $targetCurrencyId = null): void
    {
        // 查詢該金流商所有啟用中的父層 OrderType（parent=0）
        $query = OrderType::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('parent', 0)
            ->where('status', 1);

        // 如果指定了幣別，只處理該幣別的父層渠道
        if ($targetCurrencyId !== null) {
            $query->where('currency_id', $targetCurrencyId);
        }

        $disabledCount = 0;

        foreach ($query->get() as $parent) {
            $currencyKeys = $configuredKeys[$parent->currency_id] ?? [];

            // 父層 key 仍在配置中 → 保留
            if (in_array((string) $parent->key, $currencyKeys, true)) {
                continue;
            }

            // 下架父層
            $parent->update(['status' => 0]);
            $disabledCount++;

            // 連同其銀行子項一併下架
            $childDisabled = OrderType::query()
                ->where('parent', $parent->id)
                ->where('status', 1)
                ->update(['status' => 0]);
            $disabledCount += $childDisabled;

            $this->warn(sprintf(
                '      ⚠️  下架已移除的代收渠道: %s (key: %s, currency_id: %d, 子項: %d)',
                $parent->name,
                $parent->key,
                $parent->currency_id,
                $childDisabled
            ));
        }

        if ($disabledCount > 0) {
            $stats['order_type_disabled'] = ($stats['order_type_disabled'] ?? 0) + $disabledCount;
            $this->line(sprintf('    ✓ 已下架 %d 個移除的代收 OrderType（含銀行子項）', $disabledCount));
            $this->comment('    💡 若此下架其實是 config 改鍵（key 修正），請改用 --rename-key 舊key=新key 就地改鍵，保留既有 ACF/ACFOT 關聯');
        }
    }

    /**
     * 處理父層 OrderType
     */
    protected function processOrderType(
        CashFlow $cashFlow,
        array $channelData,
        array &$stats
    ): void {
        // 取得幣別 ID
        $currencyId = $this->getCurrencyId($channelData['currency']);

        if (!$currencyId) {
            $this->warn(sprintf('    警告: 幣別 %s 不存在，已略過', $channelData['currency']));

            return;
        }

        // 如果 channel 沒有指定金額限制，從 example.invalid 中讀取
        $minAmount = $channelData['min_amount'] ?? null;
        $maxAmount = $channelData['max_amount'] ?? null;

        // 如果 channel 沒有配置金額限制，則從 example.invalid 中讀取對應幣別的配置
        if ($minAmount === null || $maxAmount === null) {
            $currencyCode = $channelData['currency'];
            $cashFlowInfo = $cashFlow->info ?? [];

            if (isset($cashFlowInfo[$currencyCode])) {
                $minAmount = $minAmount ?? ($cashFlowInfo[$currencyCode]['min_amount'] ?? null);
                $maxAmount = $maxAmount ?? ($cashFlowInfo[$currencyCode]['max_amount'] ?? null);
            }
        }

        $data = [
            'parent' => 0,
            'name' => $channelData['name'],
            'key' => $channelData['key'] ?? '',
            'type' => $channelData['type'] ?? 0,
            'currency_id' => $currencyId,
            'choose_bank' => !empty($channelData['options']) ? 1 : 0,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'custom_info' => $channelData['info'] ?? null,  // 自定義 info 配置
            'amount_field' => $channelData['amount_field'] ?? true,  // false＝儲值頁不帶金額欄位（見 buildParentInfo）
        ];

        // 只在 channel 明確定義 image_path 時才帶入，
        // 否則由 prepareOrderTypeData 套用預設的國旗 CDN 圖片
        if (isset($channelData['image_path'])) {
            $data['image_path'] = $channelData['image_path'];
        }

        $channelOrderType = $this->createOrderType(
            $cashFlow,
            $data,
            $stats
        );

        // 同步多語系名稱（order_type_names）
        if (!empty($channelData['names'])) {
            $this->syncOrderTypeNames($channelOrderType, $channelData['names'], $stats);
        }

        // 支付渠道銀行選項 （order_types）
        if (!empty($channelData['options'])) {
            $this->processChildOrderTypes(
                $cashFlow,
                $channelOrderType,
                $channelData['options'],
                $currencyId,
                $stats
            );
        }
    }

    /**
     * 建立或更新單一 OrderType（父層或子層通用）
     *
     * $data 必要欄位：parent、name、key、type、currency_id、choose_bank
     * parent = 0 為頂層渠道，非 0 為銀行子項。
     *
     * @param  bool  $isChild  是否為子層 OrderType
     */
    protected function createOrderType(CashFlow $cashFlow, array $data, array &$stats, bool $isChild = false): OrderType
    {
        // 1. 構建 info 數據
        [$infoJson, $infoData] = $this->buildInfoData($data, $isChild);

        // 2. 準備 OrderType 數據
        $orderTypeData = $this->prepareOrderTypeData($cashFlow, $data, $infoJson);

        // 3. 顯式改鍵（--rename-key）：config key 修正時先就地 update 舊鍵列，
        //    使後續查找命中同一列（OT id 不變 → ACF/ACFOT 掛 order_type_id 自動跟上）
        $this->applyExplicitKeyRename($cashFlow, $data, $stats);

        // 4. 查找現有的 OrderType
        $orderType = $this->findExistingOrderType($cashFlow, $data);

        // 5. 如果存在，更新；否則創建
        if ($orderType) {
            $this->updateExistingOrderType($orderType, $data, $infoData, $stats, $isChild);
        } else {
            $orderType = $this->createNewOrderType($orderTypeData, $infoData, $stats, $isChild);
        }

        return $orderType;
    }

    /**
     * 構建 info JSON 資料
     *
     * 子層直接繼承傳入的 info；父層則組裝包含 amount 欄位的預設結構。
     *
     * @return array{0: string, 1: array} [$infoJson, $infoData]
     */
    private function buildInfoData(array $data, bool $isChild): array
    {
        if ($isChild && isset($data['info'])) {
            // 子層：從父層繼承 info
            $infoJson = is_string($data['info']) ? $data['info'] : json_encode($data['info']);
            $infoData = is_string($data['info']) ? json_decode($data['info'], true) : $data['info'];
        } else {
            // 父層：構建 info（包含預設 amount 欄位）
            $infoData = $this->buildParentInfo($data);
            $infoJson = json_encode($infoData);
        }

        return [$infoJson, $infoData];
    }

    /**
     * 組裝父層 OrderType 的 info 結構（儲值頁的表單欄位清單）。
     *
     * 預設帶一個 amount 欄位（會員輸入儲值金額）；資料檔的通道宣告 'amount_field' => false 就不帶，
     * 給「會員自行轉帳、金額由回調決定」的通道用（目前只有 CCPayment 永久地址模式，見該資料檔）。
     * 這是逐通道宣告、不依通道 type 一律套用：各家加密貨幣金流商模式不同，PayEssence 仍要會員輸入金額。
     */
    private function buildParentInfo(array $data): array
    {
        $infoData = ['data' => []];

        if (($data['amount_field'] ?? true) !== false) {
            $infoData['data'][] = ['name' => 'amount', 'type' => 'number', 'require' => 'true', 'display' => 'deposit_amount'];
        }

        // 合併 custom_info（自動包裹到 data 陣列中）
        if (isset($data['custom_info']) && is_array($data['custom_info'])) {
            $infoData['data'] = array_merge($infoData['data'], $data['custom_info']);
        }

        return $infoData;
    }

    /**
     * 將欄位套用預設值並組合成 OrderType 建立用陣列
     * 若未提供 image_path，預設使用幣別對應的 CDN 國旗圖片。
     */
    private function prepareOrderTypeData(CashFlow $cashFlow, array $data, string $infoJson): array
    {
        // 如果沒有定義 image_path，使用國旗 icon 的 CDN 圖片網址
        $imagePath = $data['image_path'] ?? sprintf(
            'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/%d.png',
            $data['currency_id']
        );

        return array_merge($this->orderTypeDefaults, [
            'cash_flow_id' => $cashFlow->id,
            'parent' => $data['parent'],
            'name' => $data['name'],
            'key' => $data['key'],
            'type' => $data['type'],
            'currency_id' => $data['currency_id'],
            'info' => $infoJson,
            'choose_bank' => $data['choose_bank'] ?? 0,
            'need_first_name' => 0,
            'image_path' => $imagePath,
            'sorts' => 0,
        ]);
    }

    /**
     * 解析並驗證 --rename-key 選項（格式：舊key=新key），結果存入 $keyRenames
     *
     * @return bool 全部格式合法返回 true；任一不合法即印錯並返回 false（handle 據此中止）
     */
    private function parseRenameKeyOption(): bool
    {
        $this->keyRenames = [];

        foreach ((array) $this->option('rename-key') as $pair) {
            $parts = explode('=', (string) $pair, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                $this->error(sprintf('❌ 無效的 --rename-key 格式: "%s"（正確格式：舊key=新key，例：49_99=49_15）', $pair));

                return false;
            }

            $this->keyRenames[trim($parts[0])] = trim($parts[1]);
        }

        return true;
    }

    /**
     * 顯式改鍵：資料的 key 為 --rename-key 的「新鍵」且 DB 存在同範疇「舊鍵」列時，就地 update 該列 key
     *
     * OT id 不變是本軌的核心——agent 層 ACF/ACFOT 掛的是 order_type_id（數字外鍵），
     * 就地改鍵後整條關聯自動跟上，不需搬移；「新建 new ＋ 下架 old」則會孤兒化既有 ACFOT。
     * type 一併同步為 config 值，使後續 findExistingOrderType（含 type 判準）命中同一列。
     *
     * 防護：新鍵列已存在（撞鍵）或舊鍵對應多列（不明確）時略過改鍵並警告，交由既有流程處理。
     */
    private function applyExplicitKeyRename(CashFlow $cashFlow, array $data, array &$stats): void
    {
        if ($this->keyRenames === []) {
            return;
        }

        $newKey = (string) $data['key'];
        $oldKey = array_search($newKey, $this->keyRenames, true);

        if ($oldKey === false) {
            return;
        }

        if ($this->findExistingOrderType($cashFlow, $data)) {
            $this->warn(sprintf('    ⚠️  改鍵略過：新鍵 "%s" 已存在同範疇 OrderType，避免撞鍵（舊鍵 "%s" 未變動）', $newKey, $oldKey));

            return;
        }

        $candidates = OrderType::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $data['currency_id'])
            ->where('parent', $data['parent'])
            ->where('key', $oldKey)
            ->get();

        if ($candidates->isEmpty()) {
            return;
        }

        if ($candidates->count() > 1) {
            $this->warn(sprintf('    ⚠️  改鍵略過：舊鍵 "%s" 對應 %d 列，語意不明確，請人工處理', $oldKey, $candidates->count()));

            return;
        }

        /** @var OrderType $orderType */
        $orderType = $candidates->first();
        $orderType->update(['key' => $newKey, 'type' => $data['type']]);
        $stats['order_type_key_renamed'] = ($stats['order_type_key_renamed'] ?? 0) + 1;

        $this->line(sprintf(
            '    ✓ 已就地改鍵: OrderType ID %d "%s" key %s → %s（ACF/ACFOT 關聯不動）',
            $orderType->id,
            $orderType->name,
            $oldKey,
            $newKey,
        ));
    }

    /** 以 cash_flow_id + currency_id + key + type + parent 查找現有 OrderType */
    private function findExistingOrderType(CashFlow $cashFlow, array $data): ?OrderType
    {
        return OrderType::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $data['currency_id'])
            ->where('key', $data['key'])
            ->where('type', $data['type'])
            ->where('parent', $data['parent'])
            ->first();
    }

    /**
     * 更新現有 OrderType：比對 info / image_path 差異，有變更才寫入
     */
    private function updateExistingOrderType(
        OrderType $orderType,
        array $data,
        array $infoData,
        array &$stats,
        bool $isChild
    ): void {
        $updateData = $this->detectChanges($orderType, $data, $infoData);

        if (!empty($updateData)) {
            $orderType->update($updateData);
            $this->line(sprintf('    ✓ OrderType 已更新: %s (ID: %s, 更新欄位: %s)',
                $orderType->name,
                $orderType->id,
                implode(', ', array_keys($updateData))
            ));
            $stats['order_type_updated']++;
        } else {
            $stats['order_type_skipped']++;
        }

        // 顯示已存在的訊息
        $this->showExistingMessage($orderType, $isChild);
    }

    /**
     * 比對 info，回傳需要更新的欄位陣列
     *
     * ! 既有 order_type 的 name 與 image_path 一律不覆寫：兩者都是營運可於後台調整的顯示資產，
     * ! 由 sync 回寫會把人工調整靜默蓋掉。image_path 只在建立時取用配置值
     * ! （見 prepareOrderTypeData），日後要換 icon 走後台，不靠重跑 sync。
     */
    private function detectChanges(OrderType $orderType, array $data, array $infoData): array
    {
        $updateData = [];

        // 比較 info 內容
        $currentInfo = is_string($orderType->info) ? json_decode($orderType->info, true) : $orderType->info;
        $currentInfoJson = json_encode($currentInfo);
        $newInfoJson = json_encode($infoData);

        if ($currentInfoJson !== $newInfoJson) {
            $updateData['info'] = $newInfoJson;
        }

        return $updateData;
    }

    /** 輸出「父層/子層 OrderType 已存在」的提示訊息 */
    private function showExistingMessage(OrderType $orderType, bool $isChild): void
    {
        if ($isChild) {
            $this->line(sprintf('    - 子層 OrderType 已存在: %s (name: %s, parent: %d)',
                $orderType->id,
                $orderType->name,
                $orderType->parent
            ));
        } else {
            $this->line(sprintf('    - 父層 OrderType 已存在: %s (name: %s, currency_id: %d)',
                $orderType->id,
                $orderType->name,
                $orderType->currency_id
            ));
        }
    }

    /** 建立 OrderType 並更新統計 */
    private function createNewOrderType(array $orderTypeData, array $infoData, array &$stats, bool $isChild): OrderType
    {
        $orderType = OrderType::create($orderTypeData);

        // 更新統計並顯示訊息
        $this->updateStatsAndShowMessage($orderType, $infoData, $stats, $isChild);

        return $orderType;
    }

    /** 更新 stats 計數並輸出建立成功訊息 */
    private function updateStatsAndShowMessage(OrderType $orderType, array $infoData, array &$stats, bool $isChild): void
    {
        if ($isChild) {
            $stats['order_type_child_inserted']++;
            $message = sprintf('    ✓ 新增子層 OrderType: %s (key: %s)',
                $orderType->name,
                $orderType->key
            );
        } else {
            $stats['order_type_parent_inserted']++;
            $message = sprintf('    ✓ 新增父層 OrderType: %s (currency_id: %d, choose_bank: %d, min: %s, max: %s)',
                $orderType->name,
                $orderType->currency_id,
                $orderType->choose_bank,
                $infoData['min_amount'] ?? 'N/A',
                $infoData['max_amount'] ?? 'N/A'
            );
        }

        $this->line($message);
    }

    /**
     * 處理子層 OrderType（銀行選項）
     *
     * 以 name + parent + cash_flow_id 判斷是否已存在。
     * 已存在：同步父層 info 與 image_path。
     * 不存在：建立新的子層，choose_bank 一律為 0。
     */
    protected function processChildOrderTypes(
        CashFlow $cashFlow,
        OrderType $parentOrderType,
        array $options,
        int $currencyId,
        array &$stats
    ): void {
        // 獲取父層的 info 和 image_path，用於同步到子層
        $parentInfo = is_string($parentOrderType->info)
            ? $parentOrderType->info
            : json_encode($parentOrderType->info);

        $parentImagePath = $parentOrderType->image_path ?? '';

        foreach ($options as $option) {
            // 驗證必要欄位
            if (!isset($option['key'])) {
                $this->warn('      警告: 選項資料缺少 \'key\' 欄位，略過');
                $stats['order_type_skipped']++;

                continue;
            }

            // 清理字串欄位（移除前後空白）
            $name = isset($option['name']) ? trim($option['name']) : '';
            $key = trim($option['key']);

            // 驗證名稱不為空
            if (empty($name)) {
                $this->warn('      警告: 選項名稱為空，略過');
                $stats['order_type_skipped']++;

                continue;
            }

            // 檢查是否已存在
            $existingChild = OrderType::query()
                ->where('cash_flow_id', $cashFlow->id)
                ->where('parent', $parentOrderType->id)
                ->where('name', $name)
                ->first();

            if ($existingChild) {
                // 子層已存在，同步父層 info；image_path 與 name 同屬營運可調的顯示資產，不回寫
                $updateData = ['info' => $parentInfo];

                $existingChild->update($updateData);
                $this->line(sprintf('      ✓ 子層 OrderType 已同步: %s (ID: %s, 更新欄位: %s)',
                    $existingChild->name,
                    $existingChild->id,
                    implode(', ', array_keys($updateData))
                ));
                $stats['order_type_updated']++;

                continue;
            }

            // 子層不存在，建立新的（繼承父層的 info 和 image_path）
            $data = [
                'parent' => $parentOrderType->id,
                'name' => $name,
                'key' => $key,
                'currency_id' => $currencyId,
                'type' => $parentOrderType->type,
                'info' => $parentInfo,  // 繼承父層的 info
                'image_path' => $parentImagePath,  // 繼承父層的 image_path
                'choose_bank' => 0,
            ];

            $this->createOrderType(
                $cashFlow,
                $data,
                $stats,
                true
            );
        }
    }

    /**
     * 同步 OrderType 多語系名稱（order_type_names）
     *
     * 根據配置中的 `names` 陣列（格式：['語言代碼' => '名稱']）建立或更新對應的
     * `order_type_names` 記錄。未知語言代碼會顯示警告並略過。
     *
     * @param  OrderType  $orderType  目標 OrderType
     * @param  array<string, string>  $names  語言代碼 → 名稱對應表，例如 ['en' => 'FPX', 'ms-my' => 'FPX 轉帳']
     * @param  array  &$stats  統計資料（引用）
     */
    protected function syncOrderTypeNames(OrderType $orderType, array $names, array &$stats): void
    {
        $languageMap = $this->getLanguageMap();

        foreach ($names as $code => $name) {
            $normalizedCode = strtolower((string) $code);
            $languageId = $languageMap[$normalizedCode] ?? null;

            if ($languageId === null) {
                $this->warn(sprintf('      ⚠️  語言代碼 "%s" 不存在，略過 order_type_name', $code));
                $stats['order_type_name_skipped']++;

                continue;
            }

            $name = trim((string) $name);

            if ($name === '') {
                $stats['order_type_name_skipped']++;

                continue;
            }

            $existing = OrderTypeName::query()
                ->where('type_id', $orderType->id)
                ->where('language_id', $languageId)
                ->first();

            if ($existing) {
                if ($existing->name !== $name) {
                    $existing->update(['name' => $name, 'admin' => 0]);
                    $this->line(sprintf('      ✓ 更新 OrderTypeName [%s]: %s', $code, $name));
                    $stats['order_type_name_updated']++;
                } else {
                    $stats['order_type_name_skipped']++;
                }
            } else {
                OrderTypeName::create([
                    'cash_flow_id' => $orderType->cash_flow_id,
                    'type_id' => $orderType->id,
                    'language_id' => $languageId,
                    'name' => $name,
                    'admin' => 0,
                ]);
                $this->line(sprintf('      ✓ 新增 OrderTypeName [%s]: %s', $code, $name));
                $stats['order_type_name_inserted']++;
            }
        }
    }

    /**
     * 取得語言代碼 → ID 對應表
     *
     * 結果快取於記憶體，整個命令週期只查詢一次 DB。
     *
     * @return array<string, int> 小寫語言代碼 → language.id
     */
    protected function getLanguageMap(): array
    {
        if ($this->languageMap === null) {
            $this->languageMap = Language::query()
                ->where('status', 1)
                ->pluck('id', 'code')
                ->mapWithKeys(fn ($id, $code) => [strtolower($code) => (int) $id])
                ->all();
        }

        return $this->languageMap;
    }

    // =========================================================================
    // 資料來源載入（cash_flows.php ＋ Data/providers/*.php）
    // =========================================================================

    /**
     * 載入資料來源
     *
     * @param  array|null  $targetIds  指定要處理的 CashFlow ID 列表，null 表示處理全部
     * @param  int|null  $targetCurrencyId  指定要處理的幣別 ID，null 表示處理全部
     */
    protected function loadSource(?array $targetIds = null, ?int $targetCurrencyId = null): array
    {
        $path = __DIR__ . '/Data/' . $this->dataFile;

        if (!file_exists($path) || !is_readable($path)) {
            $this->error(sprintf('資料檔案不存在或不可讀: %s', $path));
            throw new \RuntimeException(sprintf('無法載入資料檔案: %s', $path));
        }

        $base = require $path;

        // 如果指定了 ID，則只保留符合的資料
        if ($targetIds !== null) {
            $base = array_filter($base, fn ($item) => in_array($item['id'], $targetIds, true));

            // 重建索引，避免索引不連續
            $base = array_values($base);

            // 檢查是否所有指定的 ID 都存在於資料中
            $foundIds = array_column($base, 'id');
            $missingIds = array_diff($targetIds, $foundIds);
            if (!empty($missingIds)) {
                $this->warn(sprintf('警告: 以下 CashFlow ID 不存在於資料檔案中: %s', implode(', ', $missingIds)));
            }
        }

        $projectChannels = $this->loadProviderChannels($targetIds, $targetCurrencyId);

        // 合併 channels 到對應的 CashFlow，並確保結構正確
        foreach ($base as $index => $cashFlowData) {
            $cashFlowId = $cashFlowData['id'];

            if (isset($projectChannels['channels'][$cashFlowId])) {
                // 確保 channels 是數組，並合併新數據
                $existingChannels = $cashFlowData['channels'] ?? [];
                $newChannels = $projectChannels['channels'][$cashFlowId];

                if (!is_array($existingChannels)) {
                    $existingChannels = [];
                }

                if (!is_array($newChannels)) {
                    $newChannels = [];
                }

                $base[$index]['channels'] = array_merge($existingChannels, $newChannels);
            }
        }

        return $this->normalizeForEnvironment($base);
    }

    /**
     * 依當前 DB_CONNECTION 對應環境的主幣，對來源資料做兩項正規化：
     *  1. cash_flows.info[main_currency].rate 強制設為 1（本國幣無需匯率轉換）
     *  2. withdrawal channel.type：
     *     - currency === main_currency 且 type === FOREIGN_BANK → BANK
     *     - currency !== main_currency 且 type === BANK         → FOREIGN_BANK
     *
     * 環境未設定 main_currency（空字串）或當前連線不在 site_environments.php 內時，不做變更。
     */
    protected function normalizeForEnvironment(array $base): array
    {
        $mainCurrency = $this->resolveEnvironmentMainCurrency();

        if ($mainCurrency === '') {
            return $base;
        }

        $this->comment(sprintf(
            '套用環境主幣正規化: %s（本國銀行 rate=1, 跨幣別自動 FOREIGN_BANK）',
            $mainCurrency
        ));

        foreach ($base as &$cashFlow) {
            if (isset($cashFlow['info'][$mainCurrency])) {
                $cashFlow['info'][$mainCurrency]['rate'] = 1;
            }

            if (($cashFlow['type'] ?? null) !== 3 || empty($cashFlow['channels'])) {
                continue;
            }

            foreach ($cashFlow['channels'] as &$channel) {
                $this->flipWithdrawalChannelType($channel, $mainCurrency);
            }
            unset($channel);
        }
        unset($cashFlow);

        return $base;
    }

    /**
     * 翻轉 withdrawal channel 的 type，僅處理 BANK ↔ FOREIGN_BANK；其他 type 維持原樣。
     * 寫回時保留原本宣告風格（enum case 或 int），避免影響下游 cast。
     */
    protected function flipWithdrawalChannelType(array &$channel, string $mainCurrency): void
    {
        $type = $channel['type'] ?? null;
        $currency = $channel['currency'] ?? null;

        if ($type === null || $currency === null) {
            return;
        }

        $typeEnum = $type instanceof WithdrawalTypeEnum
            ? $type
            : WithdrawalTypeEnum::tryFrom((int) $type);

        if ($typeEnum === null) {
            return;
        }

        $isMain = ($currency === $mainCurrency);
        $writeAsInt = !($type instanceof WithdrawalTypeEnum);

        if ($isMain && $typeEnum === WithdrawalTypeEnum::FOREIGN_BANK) {
            $channel['type'] = $writeAsInt ? WithdrawalTypeEnum::BANK->value : WithdrawalTypeEnum::BANK;
        } elseif (!$isMain && $typeEnum === WithdrawalTypeEnum::BANK) {
            $channel['type'] = $writeAsInt ? WithdrawalTypeEnum::FOREIGN_BANK->value : WithdrawalTypeEnum::FOREIGN_BANK;
        }
    }

    /**
     * 載入 Provider 獨立配置文件
     *
     * 指定 targetIds 時直接根據 third_party_code 定位檔案（O(m)）；
     * 未指定則掃描整個 providers 目錄。
     */
    protected function loadProviderChannels(?array $targetIds = null, ?int $targetCurrencyId = null): array
    {
        $providersDir = __DIR__ . '/Data/providers';

        if (!is_dir($providersDir)) {
            return [];
        }

        // 取得要載入的配置文件列表
        $files = $this->getProviderFiles($targetIds, $targetCurrencyId, $providersDir);

        if (empty($files)) {
            return [];
        }

        // 處理所有配置文件
        $channels = [];

        foreach ($files as $file) {
            $this->processProviderConfigFile($file, $targetIds, $targetCurrencyId, $channels);
        }

        return empty($channels) ? [] : ['channels' => $channels];
    }

    /** 根據 targetIds 決定要載入的 provider 檔案清單 */
    protected function getProviderFiles(?array $targetIds, ?int $targetCurrencyId, string $providersDir): array
    {
        // 當指定 targetIds 時，直接定位文件
        if ($targetIds !== null) {
            $files = $this->getProviderFilesByIds($targetIds, $providersDir);

            if (empty($files)) {
                $this->comment('未找到符合指定 ID 的 Provider 配置文件');

                return [];
            }

            $this->displayLoadMessage($targetIds, $targetCurrencyId, count($files));

            return $files;
        }

        // 處理全部：掃描所有文件
        $files = glob($providersDir . '/*.php');

        if (empty($files)) {
            return [];
        }

        $this->displayLoadMessage(null, $targetCurrencyId, count($files));

        return $files;
    }

    /** 輸出「載入 N 個配置文件」的提示，依有無幣別過濾調整措辭 */
    protected function displayLoadMessage(?array $targetIds, ?int $targetCurrencyId, int $fileCount): void
    {
        $baseMessage = $targetIds !== null
            ? '使用 Provider 獨立配置模式，根據指定 ID 載入 %d 個配置文件'
            : '使用 Provider 獨立配置模式，發現 %d 個配置文件';

        if ($targetCurrencyId !== null) {
            $currencyName = $this->getCurrencyName($targetCurrencyId);
            $message = sprintf($baseMessage . '（只載入 %s 幣別）', $fileCount, $currencyName);
        } else {
            $message = sprintf($baseMessage, $fileCount);
        }

        $this->comment($message);
    }

    /** 解析單一 provider 配置檔，分別處理 deposit / withdrawal 區塊 */
    protected function processProviderConfigFile(string $file, ?array $targetIds, ?int $targetCurrencyId, array &$channels): void
    {
        $providerName = basename($file, '.php');

        $config = require $file;

        if (!is_array($config)) {
            $this->warn(sprintf('警告: Provider 配置格式錯誤: %s', $providerName));

            return;
        }

        // 處理代收配置
        if (isset($config['deposit'])) {
            $this->processDepositConfig($config['deposit'], $providerName, $targetIds, $targetCurrencyId, $channels);
        }

        // 處理代付配置
        if (isset($config['withdrawal'])) {
            $this->processWithdrawalConfig($config['withdrawal'], $providerName, $targetIds, $targetCurrencyId, $channels);
        }
    }

    /** 解析代收配置，過濾幣別後合併到 $channels */
    protected function processDepositConfig(array $depositConfig, string $providerName, ?array $targetIds, ?int $targetCurrencyId, array &$channels): void
    {
        if (!isset($depositConfig['cash_flow_id'])) {
            return;
        }

        $cashFlowId = $depositConfig['cash_flow_id'];

        // 檢查是否需要處理此 ID
        if ($targetIds !== null && !in_array($cashFlowId, $targetIds, true)) {
            return;
        }

        $depositChannels = $depositConfig['channels'] ?? [];

        // 如果指定了幣別，過濾只保留該幣別的渠道
        if ($targetCurrencyId !== null) {
            $depositChannels = $this->filterChannelsByCurrency($depositChannels, $targetCurrencyId);
        }

        if (!empty($depositChannels)) {
            $channels[$cashFlowId] = $depositChannels;
            $this->line(sprintf('  ✓ 載入 %s 代收配置 (CashFlow ID: %d, %d 個渠道)',
                $providerName,
                $cashFlowId,
                count($channels[$cashFlowId])
            ));
        } elseif ($targetCurrencyId !== null) {
            $currencyName = $this->getCurrencyName($targetCurrencyId);
            $this->warn(sprintf('  ⚠️  %s (ID: %d) 沒有配置 %s 的代收渠道',
                $providerName,
                $cashFlowId,
                $currencyName
            ));
        }
    }

    /** 解析代付配置，過濾幣別後合併到 $channels */
    protected function processWithdrawalConfig(array $withdrawalConfig, string $providerName, ?array $targetIds, ?int $targetCurrencyId, array &$channels): void
    {
        if (!isset($withdrawalConfig['cash_flow_id'])) {
            return;
        }

        $cashFlowId = $withdrawalConfig['cash_flow_id'];

        // 檢查是否需要處理此 ID
        if ($targetIds !== null && !in_array($cashFlowId, $targetIds, true)) {
            return;
        }

        $withdrawalChannels = $withdrawalConfig['channels'] ?? [];

        // 如果指定了幣別，過濾只保留該幣別的銀行
        if ($targetCurrencyId !== null) {
            $withdrawalChannels = $this->filterChannelsByCurrency($withdrawalChannels, $targetCurrencyId);
        }

        if (!empty($withdrawalChannels)) {
            $channels[$cashFlowId] = $withdrawalChannels;

            // 計算總銀行數量（涵蓋 banks / ewallets / crypto 三個分組）
            $totalBanks = 0;
            foreach ($withdrawalChannels as $channel) {
                foreach (array_keys(self::CHANNEL_BANK_GROUPS) as $groupKey) {
                    $totalBanks += count($channel[$groupKey] ?? []);
                }
            }

            $this->line(sprintf('  ✓ 載入 %s 代付配置 (CashFlow ID: %d, %d 個銀行)',
                $providerName,
                $cashFlowId,
                $totalBanks
            ));
        } elseif ($targetCurrencyId !== null) {
            $currencyName = $this->getCurrencyName($targetCurrencyId);
            $this->warn(sprintf('  ⚠️  %s (ID: %d) 沒有配置 %s 的代付銀行',
                $providerName,
                $cashFlowId,
                $currencyName
            ));
        }
    }

    /**
     * 根據 targetIds 直接定位 provider 配置檔（O(m)）
     *
     * 從 cash_flows.php 讀取 third_party_code，移除 D_/W_ 前綴後進行大小寫不敏感比對。
     * 多個 ID 對應同一 provider 時只載入一次。
     */
    protected function getProviderFilesByIds(array $targetIds, string $providersDir): array
    {
        // 載入 CashFlows 資料
        $cashFlowsPath = __DIR__ . '/Data/' . $this->dataFile;

        if (!file_exists($cashFlowsPath)) {
            return [];
        }

        $cashFlows = require $cashFlowsPath;

        if (!is_array($cashFlows)) {
            return [];
        }

        $existingProviderFiles = $this->listProviderFileNames($providersDir);

        $providerFiles = [];
        $processedProviders = []; // 追蹤已處理的 provider，避免重複（代收和代付共用同一個配置文件）

        foreach ($cashFlows as $cashFlow) {
            // 只處理指定的 ID
            if (!in_array($cashFlow['id'], $targetIds, true)) {
                continue;
            }

            $thirdPartyCode = $cashFlow['third_party_code'] ?? null;

            if (empty($thirdPartyCode)) {
                continue;
            }

            // 定位邏輯與 AddAgentCashFlow 共用 ResolvesProviderConfigFile trait（config/payment.php 為權威映射）
            $providerName = $this->resolveProviderFileName($thirdPartyCode, $existingProviderFiles);

            if ($providerName === null || isset($processedProviders[$providerName])) {
                continue;
            }

            $configPath = $providersDir . '/' . $providerName . '.php';

            if (file_exists($configPath)) {
                $providerFiles[] = $configPath;
                $processedProviders[$providerName] = true;
            }
        }

        return $providerFiles;
    }

    /**
     * 根據幣別過濾渠道配置
     *
     * @param  array  $channels  渠道配置陣列
     * @param  int  $targetCurrencyId  目標幣別 ID
     * @return array 過濾後的渠道配置
     */
    protected function filterChannelsByCurrency(array $channels, int $targetCurrencyId): array
    {
        $filtered = [];

        foreach ($channels as $channel) {
            // 檢查渠道是否有 currency 欄位
            if (!isset($channel['currency'])) {
                continue;
            }

            // 取得渠道的幣別 ID
            $channelCurrencyId = $this->getCurrencyId($channel['currency']);

            // 如果幣別匹配，保留該渠道
            if ($channelCurrencyId === $targetCurrencyId) {
                $filtered[] = $channel;
            }
        }

        return $filtered;
    }

    // =========================================================================
    // 代付 Bank 同步（平台銀行／cash_flow_banks 關聯／下架通報）
    // =========================================================================

    /**
     * 處理代付銀行設定（type = 3）
     *
     * `channels` 結構預期為：
     *  - 每個 channel 包含：
     *      - currency  : 幣別
     *      - banks     : 銀行清單，每筆包含 name / code（第三方銀行代碼）
     *
     * 行為說明：
     *  - 若指定了 targetCurrencyId，則過濾只處理該幣別的銀行
     *  - 先在平台銀行主檔 `banks` 中建立/對應一筆銀行（agent_group_id = 0），作為標準銀行資料。
     *  - 再在 `cash_flow_banks` 中建立三方金流與該銀行的關聯，紀錄 third_party_bank_code 等資訊。
     *  - 若同一 (cash_flow_id, currency_id, bank_id) 已存在關聯則略過，避免重複建立。
     *
     * 沒有 providers Data 檔的代付 provider（實例：TrustPay cf 96、MaxPay cf 114，銀行清單只存在
     * ACF 的 available_banks JSON）channels 為空、不會進到本方法。對它們跑 sync 仍會更新
     * cash_flows 的 name／info（processCashFlow 的前半段），但銀行關聯同步與下架分支不會執行、
     * 不會誤下架；也代表 sync 補不齊它們的 cash_flow_banks，要補必須先從三方文檔或現有 JSON
     * 反萃取建 Data 檔。
     *
     * @param  CashFlow  $cashFlow  金流商物件
     * @param  array  $channels  渠道列表
     * @param  array  &$stats  統計資料
     * @param  int|null  $targetCurrencyId  目標幣別 ID（null 表示處理所有幣別）
     */
    protected function processWithdrawalBanks(CashFlow $cashFlow, array $channels, array &$stats, ?int $targetCurrencyId = null): void
    {
        // 先將該金流商的所有銀行關聯設為停用（如果指定幣別，只停用該幣別的）
        $this->disableAllCashFlowBanks($cashFlow, $stats, $targetCurrencyId);

        // 收集當前配置中所有的 provider_bank_codes，用於避免誤判下架
        $configuredProviderCodes = [];

        foreach ($channels as $channel) {
            // 驗證渠道配置
            if (!$this->validateWithdrawalChannel($channel, $cashFlow->id)) {
                continue;
            }

            // 取得幣別 ID
            $currencyId = $this->getCurrencyId($channel['currency']);
            if (!$currencyId) {
                $this->warn(sprintf('    警告: 幣別 %s 不存在，已略過', $channel['currency']));

                continue;
            }

            // 如果指定了幣別過濾，檢查渠道幣別是否匹配
            if ($targetCurrencyId !== null && $currencyId !== $targetCurrencyId) {
                continue;
            }

            foreach (self::CHANNEL_BANK_GROUPS as $groupKey => $defaultType) {
                // banks 分組吃已 flip 過的 channel.type（BANK 或 FOREIGN_BANK）；
                // ewallets / crypto 直接用對應 WithdrawalTypeEnum，不受 main_currency 影響。
                $bankType = $defaultType ?? $this->resolveChannelBankType($channel);

                foreach ($channel[$groupKey] ?? [] as $bankData) {
                    // 記錄配置中的 provider_bank_code（用於下架判斷，排除匹配衝突造成的誤判）
                    if (!empty($bankData['code'] ?? '')) {
                        $configuredProviderCodes[] = trim((string) $bankData['code']);
                    }

                    $this->processSingleWithdrawalBank(
                        $cashFlow,
                        $channel,
                        $bankData,
                        $bankType,
                        $currencyId,
                        $stats
                    );
                }
            }
        }

        // 處理完後，通報真正下架（所有同代碼記錄皆為 status=0，且不在當前配置中）的銀行
        $this->reportDisabledBanks($cashFlow, $stats, $targetCurrencyId, $configuredProviderCodes);
    }

    /**
     * 停用金流商的所有銀行關聯（處理開始時的預備步驟）
     *
     * 在處理開始時先將所有關聯設為停用，後續處理過程中會重新啟用存在的銀行
     *
     * @param  CashFlow  $cashFlow  金流商
     * @param  array  &$stats  統計資料
     * @param  int|null  $targetCurrencyId  目標幣別 ID（null 表示處理所有幣別）
     */
    protected function disableAllCashFlowBanks(CashFlow $cashFlow, array &$stats, ?int $targetCurrencyId = null): void
    {
        $query = CashFlowBank::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('status', 1);

        // 如果指定了幣別，只停用該幣別的關聯
        if ($targetCurrencyId !== null) {
            $query->where('currency_id', $targetCurrencyId);
        }

        $affectedRows = $query->update(['status' => 0]);

        if ($affectedRows > 0) {
            $currencyInfo = $targetCurrencyId !== null
                ? sprintf(' (%s)', $this->getCurrencyName($targetCurrencyId))
                : '';

            $this->line(sprintf(
                '    ⚙️  預先停用 %s 的 %d 個銀行關聯%s',
                $cashFlow->name,
                $affectedRows,
                $currencyInfo
            ));
        }
    }

    /**
     * 通報真正下架的銀行
     *
     * 判斷邏輯：
     * - disableAllCashFlowBanks 預先將全部記錄設為 status=0
     * - 本次配置中存在的銀行，createCashFlowBankRelation 會將對應的 bank_id 記錄重新啟用為 status=1
     * - 若某個 provider_bank_code 下「至少有一筆 status=1」→ 銀行仍上架（舊 bank_id 的 status=0 只是孤立記錄）
     * - 若某個 provider_bank_code 下「所有記錄皆為 status=0」→ 確認不在當前配置中，才視為真正下架
     *
     * 注意：若 MAX(status)=0 但 provider_bank_code 仍在配置中，代表發生了「銀行匹配衝突」
     * （兩個配置銀行模糊匹配到同一個 bank_id，後者覆蓋前者的 provider_code），不應誤報為下架。
     *
     * 使用 GROUP BY provider_bank_code HAVING MAX(status) = 0 來識別真正下架的代碼，
     * 再排除仍存在於配置中的代碼，避免誤判。
     *
     * @param  CashFlow  $cashFlow  金流商
     * @param  array  &$stats  統計資料
     * @param  int|null  $targetCurrencyId  目標幣別 ID
     * @param  array  $configuredProviderCodes  當前配置中的所有 provider_bank_code（用於排除匹配衝突誤判）
     */
    protected function reportDisabledBanks(
        CashFlow $cashFlow,
        array &$stats,
        ?int $targetCurrencyId = null,
        array $configuredProviderCodes = []
    ): void {
        // Step 1：找出所有「同 provider_bank_code 下完全沒有 status=1」的代碼
        $allDisabledCodes = CashFlowBank::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->when($targetCurrencyId !== null, fn ($q) => $q->where('currency_id', $targetCurrencyId))
            ->selectRaw('provider_bank_code')
            ->groupBy('provider_bank_code')
            ->havingRaw('MAX(status) = 0')
            ->pluck('provider_bank_code')
            ->all();

        if (empty($allDisabledCodes)) {
            return;
        }

        // Step 2：排除仍在配置中的代碼（這些是銀行匹配衝突造成的誤判，不是真正下架）
        // 當兩個配置銀行模糊匹配到同一個 bank_id 時，後者的 provider_code 會覆蓋前者，
        // 導致前者的代碼沒有 status=1 記錄，但銀行並未真正從配置移除。
        $trulyDisabledCodes = !empty($configuredProviderCodes)
            ? array_values(array_diff($allDisabledCodes, $configuredProviderCodes))
            : $allDisabledCodes;

        if (empty($trulyDisabledCodes)) {
            return;
        }

        // Step 3：每個代碼取一筆代表記錄（含銀行名稱）供顯示
        $disabledRelations = CashFlowBank::query()
            ->with('bank:id,name')
            ->where('cash_flow_id', $cashFlow->id)
            ->when($targetCurrencyId !== null, fn ($q) => $q->where('currency_id', $targetCurrencyId))
            ->whereIn('provider_bank_code', $trulyDisabledCodes)
            ->where('status', 0)
            ->get()
            ->unique('provider_bank_code');

        foreach ($disabledRelations as $relation) {
            $bankName = $relation->bank?->name ?? sprintf('Bank#%d', $relation->bank_id);
            $this->line(sprintf(
                '  ─ <fg=yellow>%s  ⚠️  下架 (provider_code=%s)</>',
                $bankName,
                $relation->provider_bank_code
            ));
        }

        $count = $disabledRelations->count();

        if ($count > 0) {
            $stats['cash_flow_bank_disabled'] = ($stats['cash_flow_bank_disabled'] ?? 0) + $count;
        }
    }

    /**
     * 停用配置中已移除的銀行
     *
     * @param  CashFlow  $cashFlow  金流商
     * @param  array  $configuredBankCodes  配置中的銀行代碼（key: currency_id:provider_bank_code）
     * @param  array  &$stats  統計資料
     * @param  int|null  $targetCurrencyId  目標幣別 ID（null 表示處理所有幣別）
     */
    protected function disableRemovedBanks(CashFlow $cashFlow, array $configuredBankCodes, array &$stats, ?int $targetCurrencyId = null): void
    {
        // 查詢該金流商所有啟用的銀行關聯
        $query = CashFlowBank::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('status', 1);

        // 如果指定了幣別，只查詢該幣別的關聯
        if ($targetCurrencyId !== null) {
            $query->where('currency_id', $targetCurrencyId);
        }

        $existingRelations = $query->get();

        $disabledCount = 0;

        foreach ($existingRelations as $relation) {
            $key = $relation->currency_id . ':' . $relation->provider_bank_code;

            // 如果該銀行不在配置中，停用它
            if (!isset($configuredBankCodes[$key])) {
                $relation->update(['status' => 0]);
                $disabledCount++;

                $this->warn(sprintf(
                    '      ⚠️  停用已移除的銀行: CF#%d -> Bank#%d (provider_code: %s, currency_id: %d)',
                    $cashFlow->id,
                    $relation->bank_id,
                    $relation->provider_bank_code,
                    $relation->currency_id
                ));
            }
        }

        if ($disabledCount > 0) {
            $stats['cash_flow_bank_disabled'] = ($stats['cash_flow_bank_disabled'] ?? 0) + $disabledCount;
            $this->line(sprintf('    ✓ 已停用 %d 個移除的銀行', $disabledCount));
        }
    }

    /**
     * 驗證代付渠道配置
     *
     * @param  array  $channel  渠道配置
     * @param  int  $cashFlowId  CashFlow ID
     * @return bool 是否有效
     */
    protected function validateWithdrawalChannel(array $channel, int $cashFlowId): bool
    {
        if (!isset($channel['currency'])) {
            $this->warn(sprintf('    警告: 代付渠道定義不完整，缺少 currency，CashFlow ID: %d，已略過', $cashFlowId));

            return false;
        }

        return true;
    }

    /**
     * 從 channel.type 解析 WithdrawalTypeEnum
     *
     * channel.type 已由 applyMainCurrencyNormalization 翻轉為 BANK(主幣) 或 FOREIGN_BANK(跨幣)，
     * 本方法純粹做格式正規化（enum object / int / string）。
     * 解析失敗時 fallback 為 BANK，避免影響後續 banks.type 寫入。
     */
    private function resolveChannelBankType(array $channel): WithdrawalTypeEnum
    {
        $type = $channel['type'] ?? null;

        if ($type instanceof WithdrawalTypeEnum) {
            return $type;
        }

        return WithdrawalTypeEnum::tryFrom((int) $type) ?? WithdrawalTypeEnum::BANK;
    }

    /**
     * 處理單個代付銀行
     *
     * 輸出採「per-bank 區塊」格式：
     *   ─ {銀行名稱} (ID: N)
     *       名稱變更: "..." → "..." [N%]   ← 僅名稱有變化時
     *       代碼: OLD → NEW                ← 僅代碼有變化時
     *       更新: image_url | ...          ← 僅有欄位更新時
     *       金流關聯: code=XXX  ✓ 新建      ← 關聯狀態
     * 無任何變更則靜默略過，不輸出任何訊息。
     *
     * @param  CashFlow  $cashFlow  金流商
     * @param  array  $channel  渠道配置
     * @param  array  $bankData  銀行資料
     * @param  WithdrawalTypeEnum  $bankType  銀行分類（由所屬分組 banks/ewallets/crypto 決定）
     * @param  int  $currencyId  幣別 ID
     * @param  array  &$stats  統計資料
     */
    protected function processSingleWithdrawalBank(
        CashFlow $cashFlow,
        array $channel,
        array $bankData,
        WithdrawalTypeEnum $bankType,
        int $currencyId,
        array &$stats
    ): void {
        // 驗證並萃取銀行輸入
        $input = $this->extractWithdrawalBankInput($bankData);
        if ($input === null) {
            return;
        }

        [$originalName, $providerBankCode] = $input;

        // 使用智能銀行匹配
        $matchResult = StandardBankNameHelper::matchBankName(
            $channel['currency'],
            $originalName,
            $currencyId
        );

        // 信心度不足：輸出警告並記入統計後跳過
        if (!StandardBankNameHelper::isMatchReliable($matchResult)) {
            $this->reportLowConfidenceBank($originalName, $providerBankCode, $matchResult, $stats);

            return;
        }

        $standardName = $matchResult['standard_name'];

        // 初始化本銀行的變更日誌（僅收集，最後統一輸出）
        $bankLog = [];

        // 名稱有統一時記錄（原名 → 標準名）
        if ($standardName !== $originalName) {
            $bankLog[] = sprintf(
                '<fg=red>原始名稱</fg=red>: "%s" [匹配%d%%]',
                $originalName,
                $matchResult['confidence']
            );
        }

        // 1) 處理平台銀行（使用統一名稱）
        $bank = $this->ensurePlatformBank(
            $standardName,
            $currencyId,
            $channel,
            $bankType,
            $cashFlow,
            $stats,
            $bankLog
        );

        if (!$bank) {
            return;
        }

        // 2) 建立金流銀行關聯
        $this->createCashFlowBankRelation(
            $cashFlow,
            $bank,
            $currencyId,
            $providerBankCode,
            $standardName,
            $stats,
            $bankLog
        );

        // 3) 僅在有實際變更時才輸出區塊，無變更則靜默略過
        if (!empty($bankLog)) {
            $bankIdStr = $bank->wasRecentlyCreated
                ? '<fg=red>新銀行</fg=red>'
                : sprintf('ID: %d', $bank->id);
            $this->newLine();
            $this->line(sprintf('  ─ %s (%s)', $standardName, $bankIdStr));

            foreach ($bankLog as $entry) {
                $this->line(sprintf('      %s', $entry));
            }
        }
    }

    /**
     * 驗證並萃取代付銀行輸入（name / code，去除前後空白）
     *
     * @return array{0: string, 1: string}|null [originalName, providerBankCode]，資料不完整返回 null
     */
    private function extractWithdrawalBankInput(array $bankData): ?array
    {
        if (!isset($bankData['name'], $bankData['code'])) {
            $this->warn('  ⚠️  警告: 代付銀行資料缺少 name 或 code，已略過');

            return null;
        }

        $originalName = trim($bankData['name']);
        $providerBankCode = trim($bankData['code']);

        // 判空一律用 === ''，不用 empty()：合法的代付銀行碼可以是字串 "0"
        // （PayEssence 的 USDT - TRC20／USDT - ERC20 兩筆代碼就是 "0"），
        // empty() 會把它當成空值，該銀行的對應永遠建不起來。
        if ($originalName === '') {
            $this->warn('  ⚠️  警告: 代付銀行名稱為空，已略過');

            return null;
        }

        if ($providerBankCode === '') {
            $this->warn(sprintf('  ⚠️  警告: 代付銀行 %s 的代碼為空，已略過', $originalName));

            return null;
        }

        return [$originalName, $providerBankCode];
    }

    /** 低信心度銀行：以區塊格式輸出警告並記錄到統計（供 printStats 事後報告） */
    private function reportLowConfidenceBank(string $originalName, string $providerBankCode, array $matchResult, array &$stats): void
    {
        $this->newLine();
        $this->warn(sprintf(
            '  ─ ⚠️  %s [信心度: %d%%, 閾值: 70%%, 已跳過]',
            $originalName,
            $matchResult['confidence']
        ));
        $this->line(sprintf(
            '      匹配: %s | 三方代碼: %s',
            $matchResult['matched_by'],
            $providerBankCode
        ));

        // 記錄到統計中供事後檢查
        $stats['banks_low_confidence'][] = [
            'name' => $originalName,
            'code' => $providerBankCode,
            'confidence' => $matchResult['confidence'],
            'matched_by' => $matchResult['matched_by'],
            'suggested_name' => $matchResult['standard_name'],
        ];

        $stats['bank_skipped']++;
    }

    /**
     * 確保平台銀行（agent_group_id = 0）存在
     *
     * 以模糊名稱比對查找，找到則同步代碼與限額；找不到則建立新銀行。
     * 限額邏輯：min 取兩者最小、max 取兩者最大，僅在新值 > 0 時更新。
     */
    protected function ensurePlatformBank(
        string $name,
        int $currencyId,
        array $channel,
        WithdrawalTypeEnum $bankType,
        CashFlow $cashFlow,
        array &$stats,
        array &$bankLog
    ): ?Bank {
        $bank = $this->findPlatformBankByName($currencyId, $name);

        if (!$bank) {
            // 建立新銀行
            return $this->createPlatformBank($name, $currencyId, $channel, $bankType, $cashFlow, $stats, $bankLog);
        }

        // 同步銀行代碼
        $this->syncBankCode($bank, $name, $currencyId, $bankLog);

        // 更新銀行的費率、金額限制與圖標
        $bankAttributes = $this->extractBankAttributes($cashFlow, $channel['currency']);
        $updateData = $this->buildBankUpdateData($bank, $bankAttributes, $currencyId, $bankType);

        if (!empty($updateData)) {
            // 記錄變更前的值
            $oldMin = $bank->min_amount;
            $oldMax = $bank->max_amount;

            $bank->update($updateData);

            // 只在有可顯示的變更時才記錄日誌
            $changes = $this->buildBankChanges($updateData, $oldMin, $oldMax);
            if (!empty($changes)) {
                $bankLog[] = sprintf('更新: %s', implode(' | ', $changes));
            }

            $stats['bank_updated'] = ($stats['bank_updated'] ?? 0) + 1;
        } else {
            $stats['bank_skipped']++;
        }

        return $bank;
    }

    /** 以名稱查找平台銀行（agent_group_id = 0）：精確命中優先，其次模糊比對 */
    private function findPlatformBankByName(int $currencyId, string $name): ?Bank
    {
        $banks = Bank::query()
            ->where('agent_group_id', 0)
            ->where('currency_id', $currencyId)
            ->where('status', 1) // 只認 active canonical，避免鎖定已下架同名舊 row；canonical 由 dedup／rename-bank 先行保證存在。與 AgentBankSyncService 的 base 查詢 status=1 是一對孿生點，改一個要想另一個
            ->get();

        // 精確名稱優先：全域層存在「同行舊命名重複列」時（如 Hong Leong Bank 981 vs
        // (MYR) - Hong Leong Bank 1261/MYR0035），fuzzy 會依 id 序先命中舊列，
        // 使關聯永遠黏在舊 bank_code 上、無法轉到標準列
        return $banks->first(fn (Bank $b) => $b->name === $name)
            ?? $banks->first(fn (Bank $b) => $this->isSameBankName($b->name, $name));
    }

    /**
     * 建立銀行更新資料陣列
     *
     * 包含限額（min/max）與圖標；費率欄位目前暫停更新（已保留對應邏輯）。
     * type 校正：以 config 分組所代表的 WithdrawalTypeEnum 為唯一來源，與既有 row 不一致時覆寫 base bank。
     */
    private function buildBankUpdateData(Bank $bank, array $bankAttributes, int $currencyId, WithdrawalTypeEnum $bankType): array
    {
        // if ($bank->rate != $bankAttributes['rate']) {
        //     $updateData['rate'] = $bankAttributes['rate'];
        // }

        $updateData = [];

        [$newMinAmount, $newMaxAmount] = $this->resolveAmountLimits($bank, $bankAttributes);

        if ($bank->min_amount != $newMinAmount) {
            $updateData['min_amount'] = $newMinAmount;
        }

        if ($bank->max_amount != $newMaxAmount) {
            $updateData['max_amount'] = $newMaxAmount;
        }

        // 更新銀行圖標（國旗），僅在尚未設定時填入
        if (!$bank->image_url) {
            $updateData['image_url'] = $this->getBankImageUrl($currencyId);
        }

        // type 校正：分組（banks/ewallets/crypto）即為 type 的 source-of-truth，與既有 row 不一致時覆寫
        if ((int) $bank->type !== $bankType->value) {
            $updateData['type'] = $bankType->value;
        }

        return $updateData;
    }

    /**
     * 計算最終的銀行限額（取更寬鬆的值）
     *
     * 限額邏輯：
     * - min_amount 取最小值（更寬鬆的最小限制）
     * - max_amount 取最大值（更寬鬆的最大限制）
     * - 只有當新值 > 0 時才進行比較，避免將原有配置清空為 0
     *
     * @return array{0: mixed, 1: mixed} [newMinAmount, newMaxAmount]
     */
    private function resolveAmountLimits(Bank $bank, array $bankAttributes): array
    {
        $newMinAmount = $bank->min_amount;
        $newMaxAmount = $bank->max_amount;

        // 只有當金流商有配置限額（> 0）時才更新
        if ($bankAttributes['min_amount'] > 0) {
            $newMinAmount = ($bank->min_amount > 0)
                ? min($bank->min_amount, $bankAttributes['min_amount'])
                : $bankAttributes['min_amount'];
        }

        if ($bankAttributes['max_amount'] > 0) {
            $newMaxAmount = ($bank->max_amount > 0)
                ? max($bank->max_amount, $bankAttributes['max_amount'])
                : $bankAttributes['max_amount'];
        }

        return [$newMinAmount, $newMaxAmount];
    }

    /** 建立銀行變更日誌項目（顯示用） */
    private function buildBankChanges(array $updateData, mixed $oldMin, mixed $oldMax): array
    {
        $changes = [];

        if (isset($updateData['rate'])) {
            $changes[] = sprintf('rate: %s', $updateData['rate']);
        }

        if (isset($updateData['min_amount'])) {
            $changes[] = sprintf('min: %s→%s', $oldMin, $updateData['min_amount']);
        }

        if (isset($updateData['max_amount'])) {
            $changes[] = sprintf('max: %s→%s', $oldMax, $updateData['max_amount']);
        }

        if (isset($updateData['image_url'])) {
            $changes[] = 'image_url';
        }

        if (isset($updateData['type'])) {
            $typeEnum = WithdrawalTypeEnum::tryFrom($updateData['type']);
            $changes[] = sprintf('type → %d (%s)', $updateData['type'], $typeEnum?->name ?? 'UNKNOWN');
        }

        return $changes;
    }

    /** 建立平台銀行記錄並更新統計 */
    protected function createPlatformBank(
        string $name,
        int $currencyId,
        array $channel,
        WithdrawalTypeEnum $bankType,
        CashFlow $cashFlow,
        array &$stats,
        array &$bankLog
    ): Bank {
        // 取得銀行代碼
        $bankCode = $this->generateBankCode($name, $currencyId, $channel['currency'], $bankType);

        // 提取費率和金額限制
        $bankAttributes = $this->extractBankAttributes($cashFlow, $channel['currency']);

        // Phase 2 防再生：新基底列落庫即嘗試身份映射（name/alias 精確；歧義回 null 留補配軌）
        $standardMatch = $this->standardMapping()->resolveOnCreate($currencyId, $name);

        // 建立銀行記錄
        $bank = Bank::query()->create([
            'agent_group_id' => 0,
            'type' => $bankType->value,
            'name' => $name,
            'code' => $bankCode,
            'third_party_code' => null,
            'currency_id' => $currencyId,
            'standard_bank_id' => $standardMatch['standard_bank_id'] ?? null,
            'info' => null,
            'rate' => $bankAttributes['rate'],
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => $bankAttributes['min_amount'],
            'max_amount' => $bankAttributes['max_amount'],
            'sort' => 0,
            'image_url' => $this->getBankImageUrl($currencyId),
            'status' => 1,
            'remark' => null,
            'admin' => 0,
        ]);

        if ($standardMatch !== null) {
            $this->standardMapping()->auditFill((int) $bank->id, $standardMatch['standard_bank_id'], 'alias', $standardMatch['confidence'], $standardMatch['matched_by']);
        }

        $stats['bank_inserted']++;
        $bankLog[] = sprintf('<fg=green>新增</fg=green>平台銀行 (code: %s, currency_id: %d)', $bankCode, $currencyId);

        return $bank;
    }

    /**
     * 生成銀行代碼
     *
     * 優先複用相同名稱的已存在代碼；找不到則以 {幣別代碼}{4位序號} 產生新代碼，
     * 序號取「未被同幣別任何列佔用」的最小值。
     */
    protected function generateBankCode(string $name, int $currencyId, string $currencyCode, WithdrawalTypeEnum $bankType): string
    {
        // 查找是否已有相同名稱和幣別的銀行
        $existingBank = Bank::query()
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->orderBy('id')
            ->get()
            ->first(fn (Bank $candidate) => $this->isSameBankName($candidate->name, $name));

        // 複用他 group 的碼前先驗平台層（group 0）該碼未被「不同名的 active 列」佔用——
        // 走到本方法代表 group 0 沒有同名列，佔碼者必為異名行；照舊複用會在 uk_banks_active
        // 撞唯一鍵，而 sync 整趟包在單一交易內＝一列撞鍵全趟 rollback。撞則落入產新號分支。
        if ($existingBank !== null) {
            $codeHeldInPlatform = Bank::query()
                ->where('agent_group_id', 0)
                ->where('type', $bankType->value)
                ->where('currency_id', $currencyId)
                ->where('code', $existingBank->code)
                ->where('status', 1)
                ->exists();

            if (!$codeHeldInPlatform) {
                return $existingBank->code;
            }
        }

        // 產生新代碼：避開同幣別所有既有列的 code（不分 status / agent_group_id）。
        // 舊實作以 count+1 產號、不檢查佔用，遇 TPSGxx / SGDxxxx 混碼即撞唯一鍵
        // （TPower SGD0023 Wise/ANEXT 實案）；停用列舊碼一併避開，
        // 防止新銀行重用後使歷史訂單的 bank_code 語意漂移
        $usedCodes = Bank::query()
            ->where('currency_id', $currencyId)
            ->pluck('code')
            ->flip();

        for ($sequence = 1; ; $sequence++) {
            $code = $currencyCode . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

            if (!isset($usedCodes[$code])) {
                return $code;
            }
        }
    }

    /** 回傳幣別對應的 CDN 國旗圖片 URL */
    protected function getBankImageUrl(int $currencyId): string
    {
        return sprintf('https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/currency/%d.png', $currencyId);
    }

    /**
     * 從 example.invalid 讀取幣別對應的 rate / min_amount / max_amount
     *
     * 若無設定則返回預設值（rate=1, amounts=0）。
     */
    protected function extractBankAttributes(CashFlow $cashFlow, string $currencyCode): array
    {
        $info = $cashFlow->info ?? [];

        if (!isset($info[$currencyCode])) {
            return [
                'rate' => 1,
                'min_amount' => 0,
                'max_amount' => 0,
            ];
        }

        $rate = $info[$currencyCode]['rate'] ?? 1;
        $minAmount = $info[$currencyCode]['min_amount'] ?? 0;
        $maxAmount = $info[$currencyCode]['max_amount'] ?? 0;

        // 驗證 rate 合理性
        if ($rate <= 0) {
            $this->warn(sprintf('      ⚠️  金流商 %s 幣別 %s 的 rate 值異常: %s，已設為預設值 1',
                $cashFlow->name,
                $currencyCode,
                $rate
            ));
            $rate = 1;
        }

        // 驗證金額邏輯
        if ($minAmount > $maxAmount && $maxAmount > 0) {
            $this->warn(sprintf('      ⚠️  金流商 %s 幣別 %s 的金額設定錯誤: min(%s) > max(%s)',
                $cashFlow->name,
                $currencyCode,
                $minAmount,
                $maxAmount
            ));
        }

        return [
            'rate' => $rate,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
        ];
    }

    /**
     * 若找到更早建立的同名銀行，統一使用其代碼
     */
    protected function syncBankCode(Bank $bank, string $name, int $currencyId, array &$bankLog): void
    {
        // 查找更早的同名銀行（排除自己）；精確名稱優先——最早的 fuzzy 同名列常是
        // 標準化前的舊命名列（如 Hong Leong Bank/HongLeongBank），若以其為準會把
        // 標準列的 (CUR)00xx 代碼反向拖回舊碼
        //
        // 必須限定同一 agent_group_id：各支付組別的 banks 列是 base 層（group 0）的副本，
        // 代碼各組自有（VWorld group 19 的 UOB 是 SGD0001、group 116 是 UOBSG）。不限組別時
        // base 列會被拖成別組的碼，接著 AgentBankSyncService 依 base 模板在每個組別長出
        // 新碼列、會員綁定的舊碼列對不上代付 available_banks（2026-09-30 VWorld A3Pay 乾跑
        // 抓到 876 SGD0001→UOBSG、888 SGD0013→WISESG）
        $candidates = Bank::query()
            ->where('id', '!=', $bank->id)
            ->where('agent_group_id', $bank->agent_group_id)
            ->where('currency_id', $currencyId)
            ->where('status', 1)
            ->orderBy('id')
            ->get();

        // L1（ⓒ²）：僅完全同名（case-insensitive）才視為同一行，去除 isSameBankName 的模糊比對，
        // 避免把 canonical code 反向拖回模糊同名舊 row（如 Bank Islam vs Bank Islam Malaysia）
        $existingBank = $candidates->first(fn (Bank $candidate) => strcasecmp(trim($candidate->name), trim($name)) === 0);

        if (!$existingBank || $bank->code === $existingBank->code) {
            return;
        }

        // 目標碼已被同範疇（agent_group_id + type + currency_id）另一 active 列持有時跳過對齊：
        // 此情形＝同行不同碼的 active 重複（如 TPower IDR Citibank 1231/1549），
        // 對齊會撞 uk_banks_active（未上 guard 的站則靜默改成同碼、製造新重複），
        // 應由 dedup / 人工合併處理，非 sync 職責
        $targetCodeHeld = Bank::query()
            ->where('id', '!=', $bank->id)
            ->where('agent_group_id', $bank->agent_group_id)
            ->where('type', $bank->type)
            ->where('currency_id', $currencyId)
            ->where('code', $existingBank->code)
            ->where('status', 1)
            ->exists();

        if ($targetCodeHeld) {
            $bankLog[] = sprintf(
                '<fg=yellow>略過代碼對齊</> %s => %s：目標碼已被同範疇另一 active 列持有（同行不同碼重複，待 dedup／人工合併）',
                $bank->code,
                $existingBank->code
            );

            return;
        }

        $oldCode = $bank->code;
        $bank->update(['code' => $existingBank->code]);
        $bankLog[] = sprintf('代碼: %s => %s', $oldCode, $existingBank->code);
    }

    private function isSameBankName(string $left, string $right): bool
    {
        return $this->normalizeBankName($left) === $this->normalizeBankName($right);
    }

    private function normalizeBankName(string $name): string
    {
        $cleaned = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);
        $parts = preg_split('/\s+/', (string) $cleaned);
        $removeWords = [
            // 連接詞/冠詞 ETC
            'AND',
            'OF',
            'THE',

            // 國名
            'INDONESIA',
            'MALAYSIA',
            'SINGAPORE',

            // 公司類型詞彙
            'BANK',
            'BERHAD',
            'BHD',
            'CO',
            'COMPANY',
            'CORP',
            'CORPORATION',
            'GLOBAL',
            'GROUP',
            'HOLDING',
            'HOLDINGS',
            'INC',
            'INTERNATIONAL',
            'LIMITED',
            'LTD',
            'PRIVATE',
            'PT',
            'PTE',
            'PUBLIC',
            'SDN',

            // 幣別
            'MYR',
            'SGD',
            'VND',
            'VND2',
            'KVND',
            'IDR',
            'IDR2',
            'KIDR',
            'THB',
            'USDT',
            'FON',
            'GCASH',
            'BUSD',
            'MMK',
            'PayMaya',
            'HKD',
            'PGK',
            'AUD',
            'BDT',
            'TON',
            'INR',
        ];
        $parts = array_values(array_filter(
            array_map('strtoupper', $parts),
            static fn ($part) => $part !== '' && !in_array($part, $removeWords, true)
        ));
        sort($parts, SORT_STRING);

        return implode(' ', $parts);
    }

    /**
     * 建立或更新 CashFlowBank 關聯
     *
     * 已存在：更新 provider_bank_code，並重新啟用（若之前被停用）。
     * ⚠️ 例外：該列有人工覆寫標記（manual_override_at 非 null）時**不動 provider_bank_code**，
     * 其餘欄位照常同步——三方可能按商戶帳號發放銀行代號，設定檔的值對該站已不適用。
     *
     * ⚠️ 保護範圍僅止於 provider_bank_code 這一個欄位，**不保護該列會不會被重新啟用**。
     * 「銀行名稱比對不中、整列停在 status = 0 而不恢復」是另一類缺口（例如
     * cash-flow:backfill-tpower-payout-bankcodes 補的那批），標記救不了它；
     * 別把標記讀成「這列從此不會被同步動到」。
     * 不存在：建立新關聯，status = 1。
     * status 0→1 為每輪的例行重啟，靜默不輸出；真正下架由 reportDisabledBanks 通報。
     */
    protected function createCashFlowBankRelation(
        CashFlow $cashFlow,
        Bank $bank,
        int $currencyId,
        string $providerBankCode,
        string $bankName,
        array &$stats,
        array &$bankLog
    ): void {
        // 檢查關聯是否已存在
        $existsRelation = CashFlowBank::query()
            ->where('cash_flow_id', $cashFlow->id)
            ->where('currency_id', $currencyId)
            ->where('bank_id', $bank->id)
            ->first();

        if ($existsRelation) {
            // 更新現有關聯
            $updateData = [];

            // 保存舊值（在更新前）
            $oldBankCode = $existsRelation->bank_code;
            $oldProviderCode = $existsRelation->provider_bank_code;
            $oldStatus = $existsRelation->status;

            if ($existsRelation->bank_code !== $bank->code) {
                $updateData['bank_code'] = $bank->code;
            }

            // 人工覆寫保護：標記過的列不動 provider_bank_code（該站的三方代號與設定檔不同，
            // 沖回去會直接斷付）。只保護這一個欄位，整列跳過會讓 status 停在 0 反而造成代付查無。
            $isManualOverride = $existsRelation->manual_override_at !== null;

            if (!$isManualOverride) {
                // 更新 provider_bank_code，確保與配置文件保持同步
                $updateData['provider_bank_code'] = $providerBankCode;
            }

            // 重新啟用銀行（如果之前被停用）
            if ($existsRelation->status !== 1) {
                $updateData['status'] = 1;
            }

            $existsRelation->update($updateData);

            $changes = [];
            if (isset($updateData['bank_code'])) {
                $changes[] = sprintf('bank_code: %s => %s', $oldBankCode, $updateData['bank_code']);
            }

            if (!empty($changes)) {
                $bankLog[] = sprintf('關聯更新: %s', implode(' | ', $changes));
            }

            // provider_code 變更單獨高亮顯示（只在值真正改變時才記錄）
            if ($isManualOverride) {
                // 有人工覆寫標記時本來就不會寫入，只在設定檔的值與現值不同時通報「這次刻意沒改」，
                // 讓跑 sync 的人看得到哪些列被保護、為什麼，而不是靜靜地略過。
                if ((string) $oldProviderCode !== $providerBankCode) {
                    $bankLog[] = sprintf(
                        '<fg=cyan>provider_code 保留人工覆寫值 %s（設定檔為 %s；標記原因：%s）</>',
                        $oldProviderCode,
                        $providerBankCode,
                        $existsRelation->manual_override_note ?? '未註明'
                    );
                }
            } elseif ((string) $oldProviderCode !== $updateData['provider_bank_code']) {
                $bankLog[] = sprintf(
                    '<fg=yellow>provider_code: %s => %s</>',
                    $oldProviderCode,
                    $updateData['provider_bank_code']
                );
            }

            // status: 0 => 1 為每次的例行重啟（disableAllCashFlowBanks 後的正常流程），靜默更新不輸出
            // 真正下架（仍為 0）的銀行由 reportDisabledBanks 統一高亮通報

            $stats['cash_flow_bank_skipped']++;

            return;
        }

        // 建立新關聯
        CashFlowBank::query()->create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => $currencyId,
            'bank_id' => $bank->id,
            'bank_code' => $bank->code,
            'provider_bank_code' => $providerBankCode,
            'status' => 1,
            'info' => null,
        ]);

        $stats['cash_flow_bank_inserted']++;
        $bankLog[] = sprintf('<fg=green>新增</fg=green>金流關聯: provider_code=%s', $providerBankCode);
    }

    // =========================================================================
    // 共用工具與統計輸出
    // =========================================================================

    /**
     * 取得幣別 ID，結果快取 1 小時以減少 DB 查詢
     */
    protected function getCurrencyId(string $currencyCode): int
    {
        $key = 'currency:id:' . $currencyCode;
        $ttl = CacheTtl::ONE_HOUR->value;

        try {
            $id = Cache::remember($key, $ttl, function () use ($currencyCode) {
                return (int) Currency::query()->where('code', $currencyCode)->value('id');
            });

            return (int) $id;
        } catch (\Exception $e) {
            // 如果 Cache 失敗（例如 Redis 連接問題），直接查詢資料庫
            return (int) Currency::query()->where('code', $currencyCode)->value('id');
        }
    }

    /**
     * 輸出統計資訊
     */
    protected function printStats(array $stats): void
    {
        $this->info("\n執行統計:");

        $this->table(
            ['項目', '新增', '更新', '略過', '停用'],
            [
                ['CashFlow', $stats['cash_flow_inserted'], $stats['cash_flow_updated'], $stats['cash_flow_skipped'], '-'],
                ['OrderType (父層)', $stats['order_type_parent_inserted'], '-', '-', '-'],
                ['OrderType (子層)', $stats['order_type_child_inserted'], '-', '-', '-'],
                ['OrderType (更新)', '-', $stats['order_type_updated'], '-', '-'],
                ['OrderType (略過)', '-', '-', $stats['order_type_skipped'], '-'],
                ['OrderType (下架)', '-', '-', '-', $stats['order_type_disabled'] ?? 0],
                ['OrderTypeName', $stats['order_type_name_inserted'], $stats['order_type_name_updated'], $stats['order_type_name_skipped'], '-'],
                ['Bank', $stats['bank_inserted'], $stats['bank_updated'] ?? 0, $stats['bank_skipped'], '-'],
                ['CashFlowBank', $stats['cash_flow_bank_inserted'], '-', $stats['cash_flow_bank_skipped'], $stats['cash_flow_bank_disabled'] ?? 0],
            ]
        );

        // 顯示低信心度銀行的詳細報告
        if (!empty($stats['banks_low_confidence'])) {
            $this->newLine();
            $this->warn(sprintf(
                '⚠️  發現 %d 個信心度不足的銀行（已跳過）:',
                count($stats['banks_low_confidence'])
            ));
            $this->newLine();

            $lowConfidenceBanks = array_map(fn ($bank) => [
                $bank['name'],
                $bank['code'],
                $bank['confidence'] . '%',
                $bank['matched_by'],
                $bank['suggested_name'] ?? '無建議',
            ], $stats['banks_low_confidence']);

            $this->table(
                ['原始名稱', '三方代碼', '信心度', '匹配方式', '建議名稱'],
                $lowConfidenceBanks
            );

            $this->newLine();
            $this->comment('💡 建議處理方式：');
            $this->line('   1. 檢查 app/Console/Commands/CashFlow/Data/banks/{幣別}.php');
            $this->line('   2. 為這些銀行添加別名或關鍵字');
            $this->line('   3. 重新執行同步命令');
        }
    }
}
