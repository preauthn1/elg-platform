<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\Bank;
use App\Services\CashFlow\Bank\StandardBankRegistry;

/**
 * 標準銀行名稱助手類
 *
 * 用途：統一各金流商的銀行名稱，避免建立重複銀行
 *
 * 匹配策略（信心度分數，數字越高優先）：
 * - 精確匹配 (100%)：字串完全相同（忽略大小寫）即為確定答案，命中即回、不再比較
 * - 別名精確 (95%) / 模糊 (90%) / 關鍵字 (80-85%) / 字串相似度 (80%+)
 *
 * 選取方式為 **best-match（取最高分）**，非 first-match：
 *   - matchAgainstDatabase / matchAgainstConfig 都會掃描「全部」候選，回傳信心度最高者，
 *     而非陣列/資料列順序上第一個達標者。這可避免早出現的弱匹配（如輸入 "cimbniaga"
 *     內含關鍵字 "bni"，僅 83%）搶在後面正確的精確匹配（100%）之前 return。
 *   - matchBankName 的仲裁：DB 匹配 ≥90 直接採用；否則與 Config best-match 取較高者。
 *
 * @knowledge rules/standard-bank-list
 */
class StandardBankNameHelper
{
    /**
     * 匹配信心閾值（0-100）
     * 低於此值的匹配將被視為不可靠
     */
    private const int CONFIDENCE_THRESHOLD = 80;

    /**
     * 字串相似度最低要求
     */
    private const int MIN_SIMILARITY_SCORE = 80;

    /**
     * 快取標準銀行配置
     */
    private static array $configCache = [];

    /**
     * 快取資料庫銀行查詢結果
     */
    private static array $dbCache = [];

    /**
     * 快取各幣別只寫進 standard_banks 的條目名稱（已正規化，作為集合的鍵）
     *
     * @var array<string, array<string, true>>
     */
    private static array $hiddenLabelCache = [];

    // ========================================
    // 公開方法
    // ========================================

    /**
     * 智能匹配銀行名稱
     *
     * @param  string  $currency  幣別代碼（MYR, VND, SGD）
     * @param  string  $inputName  輸入的銀行名稱
     * @param  int  $currencyId  幣別 ID
     * @return array 匹配結果
     */
    public static function matchBankName(string $currency, string $inputName, int $currencyId): array
    {
        $configBanks = self::loadConfigBanks($currency);

        // 只寫進 standard_banks 的條目擁有的名稱一律比對不到，理由見 StandardBankRegistry::hiddenLabels。
        // 必須排在 DB 比對之前：DB 比對 ≥90 會直接採用，排在後面就攔不到
        if (isset(self::$hiddenLabelCache[$currency][self::normalizeString($inputName)])) {
            return self::createNoMatchResult();
        }

        $dbBanks = self::loadDatabaseBanks($currencyId);

        // 策略 1：資料庫優先匹配
        $dbMatch = self::matchAgainstDatabase($inputName, $dbBanks, $configBanks);

        // 高信心度 DB 匹配（≥90：精確、別名、模糊）直接採用，無需繼續比較
        if ($dbMatch !== null && $dbMatch['confidence'] >= 90) {
            return $dbMatch;
        }

        // 策略 2：配置文件匹配
        // 若 DB 為低信心度匹配（80-85 的 keyword/similarity），
        // 允許 Config 精確匹配搶先，避免 'maybank' 關鍵字誤判為 'Maybank (Corporate Account)'
        $configMatch = self::matchAgainstConfig($inputName, $configBanks, $currencyId);

        if ($configMatch !== null) {
            // DB 有低信心度匹配時，取信心度較高者
            if ($dbMatch !== null && $dbMatch['confidence'] >= $configMatch['confidence']) {
                return $dbMatch;
            }

            return $configMatch;
        }

        // 無 Config 匹配，退回 DB 低信心度結果（或空結果）
        return $dbMatch ?? self::createNoMatchResult();
    }

    /**
     * 檢查匹配結果是否可靠
     */
    public static function isMatchReliable(array $matchResult): bool
    {
        return $matchResult['confidence'] >= self::CONFIDENCE_THRESHOLD;
    }

    /**
     * 取得標準銀行名稱（簡化方法）
     */
    public static function getStandardName(
        string $currency,
        string $inputName,
        int $currencyId,
        ?string $fallback = null
    ): ?string {
        $result = self::matchBankName($currency, $inputName, $currencyId);

        return self::isMatchReliable($result) ? $result['standard_name'] : $fallback;
    }

    /**
     * 批量標準化銀行名稱
     */
    public static function standardizeBankList(string $currency, int $currencyId, array $banks): array
    {
        return array_map(function ($bank) use ($currency, $currencyId) {
            $matchResult = self::matchBankName($currency, $bank['name'], $currencyId);

            return [
                'original_name' => $bank['name'],
                'original_code' => $bank['code'] ?? null,
                'standard_name' => $matchResult['standard_name'],
                'confidence' => $matchResult['confidence'],
                'matched_by' => $matchResult['matched_by'],
                'existing_bank_id' => $matchResult['existing_bank_id'],
                'action' => self::determineAction($matchResult),
            ];
        }, $banks);
    }

    /**
     * 清除快取
     */
    public static function clearCache(?string $currency = null): void
    {
        if ($currency === null) {
            self::$configCache = [];
            self::$dbCache = [];
            self::$hiddenLabelCache = [];
        } else {
            unset(self::$configCache[$currency], self::$dbCache[$currency], self::$hiddenLabelCache[$currency]);
        }
    }

    // ========================================
    // 資料載入方法
    // ========================================

    /**
     * 載入配置文件中的標準銀行
     */
    private static function loadConfigBanks(string $currency): array
    {
        if (isset(self::$configCache[$currency])) {
            return self::$configCache[$currency];
        }

        $configPath = base_path(sprintf(
            'app/Console/Commands/CashFlow/Data/banks/%s.php',
            $currency
        ));

        $entries = file_exists($configPath) ? require $configPath : [];

        // 只寫進 standard_banks 的條目（sync_visible=false）不參與比對，它擁有的名稱另存一份供 matchBankName 擋下，
        // 理由見 StandardBankRegistry。用本類的 normalizeString 正規化，空白與連字號不同的寫法才擋得住
        self::$configCache[$currency] = StandardBankRegistry::syncEntries($entries);
        self::$hiddenLabelCache[$currency] = array_fill_keys(
            array_map(self::normalizeString(...), StandardBankRegistry::hiddenLabels($entries)),
            true,
        );

        return self::$configCache[$currency];
    }

    /**
     * 載入資料庫中的現有銀行
     */
    private static function loadDatabaseBanks(int $currencyId): array
    {
        $cacheKey = sprintf('currency_%d', $currencyId);

        if (isset(self::$dbCache[$cacheKey])) {
            return self::$dbCache[$cacheKey];
        }

        // orderBy('id') 讓迭代順序確定（跨執行穩定）：best-match 的 tie-break（同分保留先到者）
        // 與依賴此順序的回歸測試因此有明確、可重現的行為，不靠 MySQL 隱含排序
        self::$dbCache[$cacheKey] = Bank::query()
            ->where('currency_id', $currencyId)
            ->where('agent_group_id', 0)
            ->where('status', 1)
            ->orderBy('id')
            ->get()
            ->toArray();

        return self::$dbCache[$cacheKey];
    }

    // ========================================
    // 匹配策略方法
    // ========================================

    /**
     * 對資料庫銀行進行匹配
     */
    private static function matchAgainstDatabase(
        string $inputName,
        array $dbBanks,
        array $configBanks
    ): ?array {
        $normalizedInput = self::normalizeString($inputName);
        $best = null;

        foreach ($dbBanks as $dbBank) {
            // 1. 精確匹配資料庫銀行名稱：字串完全相同即為確定答案，直接採用
            //    （不受迭代順序影響，避免後面較弱的相似/關鍵字匹配搶先）
            if (self::isExactMatch($inputName, $dbBank['name'])) {
                return self::createMatchResult(
                    standardName: $dbBank['name'],
                    confidence: 100,
                    matchedBy: 'db_exact',
                    existingBankId: $dbBank['id']
                );
            }

            // 2. 透過配置文件別名匹配資料庫銀行（別名 95 / 模糊 90 / 關鍵字 80-85）
            $aliasMatch = self::matchDbBankViaConfigAliases(
                $inputName,
                $normalizedInput,
                $dbBank,
                $configBanks
            );

            if ($aliasMatch !== null && ($best === null || $aliasMatch['confidence'] > $best['confidence'])) {
                $best = $aliasMatch;
            }

            // 3. 字串相似度匹配
            $similarity = self::calculateStringSimilarity($normalizedInput, self::normalizeString($dbBank['name']));

            if ($similarity >= self::MIN_SIMILARITY_SCORE && ($best === null || $similarity > $best['confidence'])) {
                $best = self::createMatchResult(
                    standardName: $dbBank['name'],
                    confidence: $similarity,
                    matchedBy: sprintf('db_similarity:%d%%', $similarity),
                    existingBankId: $dbBank['id']
                );
            }
        }

        // best-match：回傳掃描全部 dbBank 後信心度最高者（非第一個命中者），
        // 避免早出現的弱匹配（如 "cimbniaga" 內含 keyword "bni"）搶在正確匹配之前
        return $best;
    }

    /**
     * 透過配置文件別名匹配資料庫銀行
     */
    private static function matchDbBankViaConfigAliases(
        string $inputName,
        string $normalizedInput,
        array $dbBank,
        array $configBanks
    ): ?array {
        foreach ($configBanks as $configBank) {
            // 確認資料庫銀行與配置標準名稱相符
            if ($dbBank['name'] !== $configBank['standard_name']) {
                continue;
            }

            // 檢查精確別名匹配
            foreach ($configBank['aliases'] ?? [] as $alias) {
                if (self::isExactMatch($inputName, $alias)) {
                    return self::createMatchResult(
                        standardName: $dbBank['name'],
                        confidence: 95,
                        matchedBy: sprintf('db_alias:%s', $alias),
                        existingBankId: $dbBank['id']
                    );
                }
            }

            // 檢查模糊別名匹配
            foreach ($configBank['aliases'] ?? [] as $alias) {
                if ($normalizedInput === self::normalizeString($alias)) {
                    return self::createMatchResult(
                        standardName: $dbBank['name'],
                        confidence: 90,
                        matchedBy: sprintf('db_fuzzy_alias:%s', $alias),
                        existingBankId: $dbBank['id']
                    );
                }
            }

            // 檢查關鍵字匹配
            $keywordMatch = self::matchByKeywords($normalizedInput, $configBank['keywords'] ?? []);

            if ($keywordMatch !== null) {
                return self::createMatchResult(
                    standardName: $dbBank['name'],
                    confidence: $keywordMatch['confidence'],
                    matchedBy: sprintf('db_keyword:%s', $keywordMatch['keyword']),
                    existingBankId: $dbBank['id']
                );
            }
        }

        return null;
    }

    /**
     * 對配置文件進行匹配
     */
    private static function matchAgainstConfig(
        string $inputName,
        array $configBanks,
        int $currencyId
    ): ?array {
        $normalizedInput = self::normalizeString($inputName);

        // best-match：掃描全部 config entry，僅保留信心度最高者（standard_name / confidence / matched_by），
        // findExistingBankId 延後到選出勝者才查一次，避免對每個候選都打 DB（N+1）
        $best = null;
        $consider = static function (string $standardName, int $confidence, string $matchedBy) use (&$best): void {
            if ($best === null || $confidence > $best['confidence']) {
                $best = [
                    'standard_name' => $standardName,
                    'confidence' => $confidence,
                    'matched_by' => $matchedBy,
                ];
            }
        };

        foreach ($configBanks as $configBank) {
            $standardName = $configBank['standard_name'];

            // 1. 精確匹配標準名稱：確定答案，直接採用（只查一次 DB）
            if (self::isExactMatch($inputName, $standardName)) {
                return self::createMatchResult(
                    standardName: $standardName,
                    confidence: 100,
                    matchedBy: 'config_exact',
                    existingBankId: self::findExistingBankId($standardName, $currencyId)
                );
            }

            // 2. 精確匹配別名（95）
            foreach ($configBank['aliases'] ?? [] as $alias) {
                if (self::isExactMatch($inputName, $alias)) {
                    $consider($standardName, 95, sprintf('config_alias:%s', $alias));
                    break;
                }
            }

            // 3. 模糊匹配標準名稱（90，移除空格、大小寫）
            if ($normalizedInput === self::normalizeString($standardName)) {
                $consider($standardName, 90, 'config_fuzzy');
            }

            // 4. 模糊匹配別名（90）
            foreach ($configBank['aliases'] ?? [] as $alias) {
                if ($normalizedInput === self::normalizeString($alias)) {
                    $consider($standardName, 90, sprintf('config_fuzzy_alias:%s', $alias));
                    break;
                }
            }

            // 5. 關鍵字匹配（80-85）
            $keywordMatch = self::matchByKeywords($normalizedInput, $configBank['keywords'] ?? []);

            if ($keywordMatch !== null) {
                $consider($standardName, $keywordMatch['confidence'], sprintf('config_keyword:%s', $keywordMatch['keyword']));
            }
        }

        if ($best === null) {
            return null;
        }

        return self::createMatchResult(
            standardName: $best['standard_name'],
            confidence: $best['confidence'],
            matchedBy: $best['matched_by'],
            existingBankId: self::findExistingBankId($best['standard_name'], $currencyId)
        );
    }

    // ========================================
    // 輔助方法
    // ========================================

    /**
     * 精確匹配（忽略大小寫）
     */
    private static function isExactMatch(string $str1, string $str2): bool
    {
        return strcasecmp(trim($str1), trim($str2)) === 0;
    }

    /**
     * 標準化字串（移除空格、特殊字符、轉小寫）
     */
    private static function normalizeString(string $string): string
    {
        // 移除幣別前綴 "(MYR) - "
        $string = preg_replace('/^\([A-Z]{3}\)\s*-\s*/', '', $string);

        // 轉小寫並移除空格和特殊字符（含括號，避免 "(Corporate Account)" 干擾比對）
        return strtolower(preg_replace('/[\s\-_\'.()\[\]]+/', '', $string));
    }

    /**
     * 計算字串相似度（0-100）
     */
    private static function calculateStringSimilarity(string $str1, string $str2): int
    {
        $maxLen = max(strlen($str1), strlen($str2));

        if ($maxLen === 0) {
            return 100;
        }

        $levenshtein = levenshtein($str1, $str2);
        $similarity = (1 - ($levenshtein / $maxLen)) * 100;

        return (int) max(0, min(100, $similarity));
    }

    /**
     * 關鍵字匹配
     *
     * @return array|null ['confidence' => int, 'keyword' => string]
     */
    private static function matchByKeywords(string $normalizedInput, array $keywords): ?array
    {
        if (empty($keywords)) {
            return null;
        }

        $bestMatch = null;
        $highestConfidence = 0;

        foreach ($keywords as $keyword) {
            $normalizedKeyword = self::normalizeString($keyword);

            if (!str_contains($normalizedInput, $normalizedKeyword)) {
                continue;
            }

            // 計算信心度：關鍵字越長、佔輸入比例越高，信心度越高
            $ratio = strlen($normalizedKeyword) / strlen($normalizedInput);
            $confidence = (int) min(85, 80 + ($ratio * 10));

            if ($confidence >= self::CONFIDENCE_THRESHOLD && $confidence > $highestConfidence) {
                $bestMatch = [
                    'confidence' => $confidence,
                    'keyword' => $keyword,
                ];
                $highestConfidence = $confidence;
            }
        }

        return $bestMatch;
    }

    /**
     * 查找資料庫中現有銀行的 ID
     */
    private static function findExistingBankId(string $bankName, int $currencyId): ?int
    {
        $bank = Bank::query()
            ->where('name', $bankName)
            ->where('currency_id', $currencyId)
            ->where('agent_group_id', 0)
            ->first();

        return $bank?->id;
    }

    /**
     * 決定處理動作
     */
    private static function determineAction(array $matchResult): string
    {
        if (!self::isMatchReliable($matchResult)) {
            return 'skip';
        }

        return $matchResult['existing_bank_id'] ? 'update' : 'create';
    }

    // ========================================
    // 結果建構方法
    // ========================================

    /**
     * 建立匹配結果
     */
    private static function createMatchResult(
        string $standardName,
        int $confidence,
        string $matchedBy,
        ?int $existingBankId
    ): array {
        return [
            'standard_name' => $standardName,
            'confidence' => $confidence,
            'matched_by' => $matchedBy,
            'existing_bank_id' => $existingBankId,
        ];
    }

    /**
     * 建立無匹配結果
     */
    private static function createNoMatchResult(): array
    {
        return [
            'standard_name' => null,
            'confidence' => 0,
            'matched_by' => 'no_match',
            'existing_bank_id' => null,
        ];
    }
}
