<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Helpers\StandardBankNameHelper;
use App\Models\Bank;
use App\Models\Currency;
use Illuminate\Support\Collection;

/**
 * 銀行配對稽核 Service
 *
 * 提供 4 類偵測：
 * - lost                 : DB 銀行 row 無法匹配任何 standard
 * - low_confidence_feed  : provider feed 進來時匹配信心度 < 80
 * - mismatch             : DB row name 與 standard 不一致
 * - duplicate            : 多筆 DB row 解析到同一個 standard
 *
 * 兩個命令共用：
 * - bank:audit-feed       使用 lost + low_confidence_feed
 * - bank:fix-mismatch     使用 mismatch + duplicate
 */
class BankMismatchAuditor
{
    // 85 而非 80：similar_text 對短名容易湊到 80 出頭（"Sing Cash"×"ING Bank"＝83 曾誤配並連帶誤判 duplicate），
    // 85 以上實測皆為合法配對（Deutsche Bank AG、Sumitomo Mitsui 等縮寫補全型）
    private const int CONFIDENCE_THRESHOLD = 85;

    /**
     * 對單一幣別執行完整稽核
     *
     * `$agentGroupId != 0`＝稽核 group 層 banks（2026-08-26 新增，group 層收斂入口）：
     * mismatch／duplicate 判定與 base 同一套 standard 解析；lost／low_confidence_feed 是
     * base 配置（provider feed）稽核、對 group 層無語意，回空陣列。
     *
     * `$includeInactive`＝連 `status=0` 的列一起稽核（2026-08-27 新增，`--include-inactive`）＝
     * **停用列的命名盤點**。原始動機是「金流資料同時建測試組與正式組兩份、正式組先設 `status=0`
     * 驗證通過才啟用」⇒ 那些列要到上線才第一次進稽核視野。⚠️ **但實測結果不支持這個動機**：
     * TPower 三層的 `status=0` 列絕大多數是**已退場的舊列**（例 g674 VND 16 筆全是 2023-12-27
     * 建立的 `TPVNxx` 白標舊碼，canonical `VND0041` 等 2026-02 才建並存活），「正式組先設
     * `status=0` 待上線」的型態在現存資料中沒有明顯呈現。⇒ 本旗標的實際用途是盤點停用列的
     * 命名狀態，**不能當成「上線前攔截 drift」的工具**。
     *
     * **呼叫端必須維持唯讀**（`BankFixMismatchCommand` 以 fail-closed 拒絕與 `--apply` 併用）：
     * 停用列的修復方向要另外判斷——已退場的舊列不該被 rename 成 canonical 名（那會讓歷史單據
     * 的 `bank_name` snapshot 與現況對不上），同一套 rename／merge 判準不適用。
     *
     * @return array{lost: array, low_confidence_feed: array, mismatch: array, duplicate: array}
     */
    public function auditCurrency(string $currency, int $currencyId, int $agentGroupId = 0, bool $includeInactive = false): array
    {
        $dbBanks = $this->loadDbBanks($currencyId, $agentGroupId, $includeInactive);

        return [
            'lost' => $agentGroupId === 0 ? $this->detectLost($dbBanks, $currency, $currencyId) : [],
            'low_confidence_feed' => $agentGroupId === 0 ? $this->detectLowConfidenceFeed($currency, $currencyId) : [],
            'mismatch' => $this->detectMismatch($dbBanks, $currency, $currencyId),
            'duplicate' => $this->detectDuplicate($dbBanks, $currency, $currencyId),
        ];
    }

    /**
     * 列出支援的幣別
     *
     * 從 app/Console/Commands/CashFlow/Data/banks/ 目錄自動探索：
     * 凡符合 3 字母大寫的 {CODE}.php 檔皆視為候選幣別代碼，
     * 再用 Currency 表查回 currency_id。新增幣別只需新增 {CODE}.php，
     * 不必同步修改本方法。
     *
     * @return array<string, int> ['MYR' => 1, ...]
     */
    public function supportedCurrencies(): array
    {
        $files = glob(base_path('app/Console/Commands/CashFlow/Data/banks/*.php')) ?: [];

        $codes = collect($files)
            ->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME))
            ->filter(fn (string $name): bool => preg_match('/^[A-Z]{3}$/', $name) === 1)
            ->values()
            ->all();

        return Currency::query()
            ->whereIn('code', $codes)
            ->pluck('id', 'code')
            ->all();
    }

    protected function loadDbBanks(int $currencyId, int $agentGroupId = 0, bool $includeInactive = false): Collection
    {
        return Bank::query()
            ->where('currency_id', $currencyId)
            ->where('agent_group_id', $agentGroupId)
            // 預設路徑（status=1）的行為刻意保持原狀：「已標記刪除卻 status=1」的矛盾列
            // 本來就會被撈到，那是既有行為（2026-08-27 RB 拍板維持現狀）。
            //
            // 放行 status=0 時要濾掉兩類雜訊，否則報表會被「已退場的舊列」洗掉，
            // 看不見本旗標真正要找的「尚未上線、卻已帶 drift 的資料」：
            // 1. 軟刪列——`Bank` 無 SoftDeletes ⇒ 無全域 scope 可依賴，得自己濾。
            // 2. 曾作為 loser 被收斂停用的列——它們的名稱不會被 rename（rename 只處理
            //    status=1），所以必然報 mismatch，且必然與存活方配成 duplicate。
            //    TPower g674 VND 實測 16 筆 mismatch 全屬此類（例 `520 "(VND) - VIB"`
            //    停用、配 `1683` canonical 名存活）＝正常退場狀態，不是待修 drift。
            ->when(
                $includeInactive,
                fn ($query) => $query
                    ->whereNull('deleted_at')
                    ->whereNotIn('id', fn ($sub) => $sub
                        ->select('loser_bank_id')
                        ->from('bank_dedup_logs')
                        ->whereNotNull('loser_bank_id')),
                fn ($query) => $query->where('status', 1),
            )
            ->get();
    }

    protected function detectLost(Collection $dbBanks, string $currency, int $currencyId): array
    {
        $lost = [];

        foreach ($dbBanks as $bank) {
            $match = $this->matchExcludingBank($currency, $bank->name, $currencyId, $bank->id);

            if ($match['confidence'] < self::CONFIDENCE_THRESHOLD) {
                $lost[] = [
                    'bank_id' => $bank->id,
                    'name' => $bank->name,
                    'confidence' => $match['confidence'],
                    'best_guess_standard' => $match['standard_name'],
                ];
            }
        }

        return $lost;
    }

    /**
     * 跑匹配時排除指定 bank_id（避免自我精確匹配）
     *
     * 由於 StandardBankNameHelper 沒有公開 exclude 機制，
     * 採用「跳過 DB 匹配結果」策略：matched_by 以 db_ 開頭即視為自我匹配（無視 confidence），
     * 改回傳 config-only 結果。
     */
    protected function matchExcludingBank(string $currency, string $name, int $currencyId, int $excludeBankId): array
    {
        return $this->matchAgainstConfigOnly($currency, $name, $currencyId);
    }

    /**
     * 只用 config 做匹配（跳過 DB 匹配結果）
     *
     * 用於 detect lost / mismatch / duplicate，避免 DB 自我匹配遮蔽真實匹配能力。
     *
     * 策略：呼叫 matchBankName，若 matched_by 以 db_ 開頭視為 DB 自我匹配，
     * 退回 config-only 路徑（直接讀 Data/banks/{currency}.php 比對 standard_name 與 aliases）。
     */
    protected function matchAgainstConfigOnly(string $currency, string $name, int $currencyId): array
    {
        $result = StandardBankNameHelper::matchBankName($currency, $name, $currencyId);

        if (!str_starts_with($result['matched_by'] ?? '', 'db_')) {
            return $result;
        }

        // DB 自我匹配，改用 config 直接比對
        return $this->lookupInConfig($currency, $name);
    }

    /**
     * 直接讀 Data/banks/{currency}.php 比對 standard_name 與 aliases
     *
     * 不走 DB 路徑，回傳結構與 StandardBankNameHelper::matchBankName 一致。
     */
    protected function lookupInConfig(string $currency, string $name): array
    {
        $configPath = base_path(sprintf(
            'app/Console/Commands/CashFlow/Data/banks/%s.php',
            $currency
        ));

        if (!file_exists($configPath)) {
            return $this->noMatchResult();
        }

        $configBanks = StandardBankRegistry::syncEntries(require $configPath);
        $needle = strtolower(trim($name));
        $needleNoPrefix = strtolower(trim(
            preg_replace(sprintf('/^\(%s\)\s*-\s*/i', preg_quote($currency, '/')), '', $name)
        ));

        foreach ($configBanks as $bank) {
            // 1. Standard exact match (full names with prefix)
            if (strtolower(trim($bank['standard_name'])) === $needle) {
                return [
                    'standard_name' => $bank['standard_name'],
                    'confidence' => 100,
                    'matched_by' => 'config_exact',
                    'existing_bank_id' => null,
                ];
            }

            // 2. Alias match — try DB name as-is AND with prefix stripped
            //    (aliases typically don't carry the (CUR) - prefix)
            foreach ($bank['aliases'] ?? [] as $alias) {
                $aliasLower = strtolower(trim($alias));
                if ($aliasLower === $needle || $aliasLower === $needleNoPrefix) {
                    return [
                        'standard_name' => $bank['standard_name'],
                        'confidence' => 95,
                        'matched_by' => sprintf('config_alias:%s', $alias),
                        'existing_bank_id' => null,
                    ];
                }
            }
        }

        return $this->noMatchResult();
    }

    /**
     * @return array{standard_name: null, confidence: int, matched_by: string, existing_bank_id: null}
     */
    protected function noMatchResult(): array
    {
        return [
            'standard_name' => null,
            'confidence' => 0,
            'matched_by' => 'no_match',
            'existing_bank_id' => null,
        ];
    }

    protected function detectLowConfidenceFeed(string $currency, int $currencyId): array
    {
        $lowConf = [];
        $feeds = $this->loadProviderFeeds($currency);

        foreach ($feeds as $feed) {
            if ($feed['name'] === '') {
                continue;
            }

            $match = StandardBankNameHelper::matchBankName($currency, $feed['name'], $currencyId);

            if ($match['confidence'] < self::CONFIDENCE_THRESHOLD) {
                $lowConf[] = [
                    'provider' => $feed['provider'],
                    'feed_name' => $feed['name'],
                    'feed_code' => $feed['code'],
                    'confidence' => $match['confidence'],
                ];
            }
        }

        return $lowConf;
    }

    /**
     * 讀取所有 provider 配置中該幣別的銀行 feed
     *
     * @return array<int, array{provider: string, name: string, code: string}>
     */
    protected function loadProviderFeeds(string $currency): array
    {
        $providersDir = base_path('app/Console/Commands/CashFlow/Data/providers');
        $feeds = [];

        foreach (glob($providersDir . '/*.php') ?: [] as $file) {
            $providerName = basename($file, '.php');
            $config = require $file;

            $banks = $this->extractBanksFromProviderConfig($config, $currency);

            foreach ($banks as $bank) {
                $feeds[] = [
                    'provider' => $providerName,
                    'name' => (string) ($bank['name'] ?? ''),
                    'code' => (string) ($bank['code'] ?? ''),
                ];
            }
        }

        return $feeds;
    }

    /**
     * 從 provider config 抽取指定幣別的銀行清單
     *
     * 支援兩種結構：
     * - withdrawal.banks[<currency>]
     * - withdrawal.channels[].banks（filtered by channel.currency）
     */
    protected function extractBanksFromProviderConfig(array $config, string $currency): array
    {
        if (isset($config['withdrawal']['banks'][$currency])) {
            return $config['withdrawal']['banks'][$currency];
        }

        $banks = [];
        foreach ($config['withdrawal']['channels'] ?? [] as $channel) {
            if (($channel['currency'] ?? null) !== $currency) {
                continue;
            }

            foreach ($channel['banks'] ?? [] as $bank) {
                $banks[] = $bank;
            }
        }

        return $banks;
    }

    /**
     * 偵測 DB 銀行 row 的 name 與 standard 不一致
     *
     * 條件：透過 config 解析得到的 standard_name（confidence ≥ 80）
     * 與 DB row 現有 name 不同 → 應改名為 standard_name。
     */
    protected function detectMismatch(Collection $dbBanks, string $currency, int $currencyId): array
    {
        $mismatch = [];

        foreach ($dbBanks as $bank) {
            $match = $this->matchAgainstConfigOnly($currency, $bank->name, $currencyId);

            if ($match['confidence'] < self::CONFIDENCE_THRESHOLD) {
                continue; // 這類進 lost
            }

            if ($match['standard_name'] !== $bank->name) {
                $mismatch[] = [
                    'bank_id' => $bank->id,
                    'current_name' => $bank->name,
                    'target_standard' => $match['standard_name'],
                    'confidence' => $match['confidence'],
                ];
            }
        }

        return $mismatch;
    }

    /**
     * 偵測多筆 DB row 解析到同一個 standard
     *
     * 同一 standard 下會挑 id 最小者為 primary，其餘列為 duplicates 待合併。
     */
    protected function detectDuplicate(Collection $dbBanks, string $currency, int $currencyId): array
    {
        $byStandard = [];

        foreach ($dbBanks as $bank) {
            $match = $this->matchAgainstConfigOnly($currency, $bank->name, $currencyId);

            if ($match['confidence'] < self::CONFIDENCE_THRESHOLD) {
                continue;
            }

            $standard = $match['standard_name'];
            $byStandard[$standard][] = $bank;
        }

        $duplicates = [];
        foreach ($byStandard as $standard => $banks) {
            if (count($banks) < 2) {
                continue;
            }

            usort($banks, fn ($a, $b) => $a->id <=> $b->id);
            $primary = array_shift($banks);

            $duplicates[] = [
                'standard_name' => $standard,
                'primary_bank_id' => $primary->id,
                'primary_name' => $primary->name,
                'duplicates' => array_map(
                    fn ($b) => ['bank_id' => $b->id, 'name' => $b->name],
                    $banks
                ),
            ];
        }

        return $duplicates;
    }
}
