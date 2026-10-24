<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\MemberFieldKeyEnum;
use App\Enums\MemberRegisterLimitFieldEnum;
use App\Models\AgentSettingDefine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 3969/3970：把舊個性化設置的現值搬進 type=6 設定（選用工具，非升級必跑）。
 *
 * 一次性升級工具：把舊值搬過來當新表的起點。搬完即由新表接手，
 * 規則來源已於階段二全面切為 type=6（不再 fallback 舊個性化）。
 * 6 白標皆已執行完畢，保留此指令供新開白標／新建組織初始化時使用。
 *
 * 回填來源（不寫死數字，逐白標讀該庫現值，故每個白標跑同一支指令即可）：
 *   #2013 手機號綁定上限 → agent_register_limits.phone
 *   #2016 信箱綁定上限   → agent_register_limits.email
 *   #9107 註冊允許邀請碼為空 → agent_member_field_settings.referral_code（page_type=1）
 * #6608 真實姓名允許重複實查 6 白標全為「允許重複」= 不限制，與 seed 預設一致，不需回填。
 *
 * 綁定上限在該組織查無舊設定時，預設略過（新表維持未啟用＝不限）。帶 --missing-as-default
 * 則改以 1 回填，讓新表等於執行期的現行生效值。執行期以 agent_setting_defines（設定定義表）為主表、
 * 左接代理所屬個性化組別的值，取「組別值，沒有就用定義的預設值」，最後查無仍當 1。因此：
 *   - 該庫的設定定義表沒有這個綁定上限 key：執行期不讀組別值、一律是 1，回填也一律用 1，不採組別舊值。
 *   - 有定義、組織查無舊值：回填 1（#2013 的定義預設值為 1）。
 *   - 舊值存在但小於 1（0 或空字串）照樣略過，執行期讀到這類值也不會當成 1。
 * 只想補綁定上限時加 --only-binding-limits，推薦碼必填整段不處理，避免順帶改到已人工調整過的推薦碼設定
 * （推薦碼那列的初始狀態與「人工改回非必填」無法區分）。
 *
 * 主值的取法與限制：執行期逐代理讀「自己所屬個性化組別」的值，本命令則每個組織只取一個主值，
 * 從「有舊值列的個性化組別」中取代理數最多者的值，寫進該組織有代理綁定的 type=6 組別。
 * 兩者只在以下情況一致：組織完全沒有舊值，或組織內每個有代理的個性化組別都設了同一個值。
 * 不一致的情況與輸出：
 *   - 多個組別的值不同（如 VWorld #2013 有 1/50/5）：取代理數最多者，並在輸出列出被忽略的值供人工覆核。
 *   - 只有部分組別有舊值、其他組別沒有：沒有舊值的組別在執行期是定義預設值，命令卻採有舊值組別的值；
 *     這種情況舊值只有一種，不會出現上述警告，需人工比對組別設定。
 *
 * 用法（每個白標各自執行，先 dry-run 確認讀到的值）：
 *   php artisan patch:backfill-member-field-legacy-values --dry-run
 *   php artisan patch:backfill-member-field-legacy-values
 *   php artisan patch:backfill-member-field-legacy-values --dry-run --missing-as-default --only-binding-limits
 *
 * dry-run 每項另印「將寫入 N 列」：依與正式執行相同的條件計數，已被人工設定或已回填過的列不算。
 *
 * target/tickets#3969、target/tickets#4459
 */
class BackfillMemberFieldLegacyValues extends Command
{
    protected $signature = 'patch:backfill-member-field-legacy-values
        {--dry-run : 只預覽將回填的值與數量，不寫入}
        {--force : 覆蓋已被人工設定過的值（預設只回填仍為初始狀態的列）}
        {--missing-as-default : 綁定上限查無舊設定、或該庫設定定義表沒有該 key 時，以 1 回填（後者不採組別舊值，對齊執行期）}
        {--only-binding-limits : 只回填綁定上限，不處理推薦碼必填}';

    protected $description = '3969 升級回填：將舊個性化綁定上限/推薦碼必填的現值搬進 type=6 設定';

    /** 會員欄位設置組別類型 */
    private const AGENT_GROUP_TYPE = 6;

    /** 幣別：MYR */
    private const CURRENCY_ID = 1;

    /** 註冊頁 page_type */
    private const PAGE_TYPE_REGISTER = 1;

    /** 綁定上限查無舊個性化設置時，執行期採用的值（#2013 的 agent_setting_defines 預設值亦為 1） */
    private const LEGACY_BINDING_LIMIT_DEFAULT = 1;

    /**
     * 該庫設定定義表沒有的綁定上限 key。執行期讀不到這些 key 的組別值，一律當 1。
     *
     * @var list<int>
     */
    private array $undefinedBindingLimitKeys = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $missingAsDefault = (bool) $this->option('missing-as-default');
        $onlyBindingLimits = (bool) $this->option('only-binding-limits');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN：不寫入資料庫');
        }
        if ($force) {
            $this->warn('🔧 FORCE：將覆蓋已被人工設定過的值');
        }
        if ($missingAsDefault) {
            $this->warn('綁定上限查無舊設定時，以預設 ' . self::LEGACY_BINDING_LIMIT_DEFAULT . ' 回填');

            $bindingLimitKeys = [
                AgentCustomizedSettingKeyEnum::MOBILE_BIND_LIMIT,
                AgentCustomizedSettingKeyEnum::EMAIL_BIND_LIMIT,
            ];
            $definedKeys = AgentSettingDefine::query()
                ->whereIn('setting_id', $bindingLimitKeys)
                ->pluck('setting_id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $this->undefinedBindingLimitKeys = array_values(array_diff($bindingLimitKeys, $definedKeys));
        }
        if ($onlyBindingLimits) {
            $this->warn('只回填綁定上限，不處理推薦碼必填');
        }

        // type=6 組別必須先由 init 腳本建立
        $groups = DB::table('agent_groups')
            ->where('agent_group_type', self::AGENT_GROUP_TYPE)
            ->get(['id', 'organization_id']);

        if ($groups->isEmpty()) {
            $this->error('查無 type=6 組別，請先執行 patch:init-member-field-settings');

            return self::FAILURE;
        }

        $now = now();
        $filledLimits = 0;
        $filledReferral = 0;
        $warnings = [];
        // 同組織的舊值只查一次（後台可為同組織建多個 type=6 組別，如複製出來的設定組）
        $dominantCache = [];

        foreach ($groups as $group) {
            $orgId = (int) $group->organization_id;
            $groupId = (int) $group->id;

            // 同組織可有多個 type=6 組別（後台複製出來的設定組）。只回填「實際有代理綁定」的組別，
            // 避免把組織主值蓋進刻意複製成不同設定的草稿組別。
            $boundAgents = DB::table('agents')->where('member_field_setting_group_id', $groupId)->count();
            if ($boundAgents === 0) {
                $this->line("組織 {$orgId}（組別 {$groupId}）：無代理綁定，略過");

                continue;
            }

            $this->line("組織 {$orgId}（type=6 組別 {$groupId}，{$boundAgents} 代理）");

            $filledLimits += $this->backfillBindingLimits($orgId, $groupId, $dominantCache, $warnings, $dryRun, $force, $missingAsDefault, $now);
            if ($onlyBindingLimits) {
                continue;
            }
            $filledReferral += $this->backfillReferralRequired($orgId, $groupId, $dominantCache, $warnings, $dryRun, $force, $now);
        }

        $this->newLine();
        $this->info("回填綁定上限：{$filledLimits} 項");
        $this->info("回填推薦碼必填：{$filledReferral} 個組織");

        if ($warnings !== []) {
            $this->newLine();
            $this->warn('⚠️  以下組織的個性化組別值不一致，已取代理數最多者為主值，請人工覆核：');
            foreach ($warnings as $w) {
                $this->warn("  {$w}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN 結束，未寫入。確認上列值正確後移除 --dry-run 再執行。');
        }

        return self::SUCCESS;
    }

    /**
     * 回填綁定上限：#2013 → phone、#2016 → email。回傳回填項數。
     *
     * @param  array<string, array{0: ?string, 1: ?string}>  $dominantCache
     * @param  array<string, string>  $warnings
     */
    private function backfillBindingLimits(
        int $orgId,
        int $groupId,
        array &$dominantCache,
        array &$warnings,
        bool $dryRun,
        bool $force,
        bool $missingAsDefault,
        mixed $now,
    ): int {
        $filled = 0;

        foreach ([
            AgentCustomizedSettingKeyEnum::MOBILE_BIND_LIMIT => MemberRegisterLimitFieldEnum::PHONE->value,
            AgentCustomizedSettingKeyEnum::EMAIL_BIND_LIMIT => MemberRegisterLimitFieldEnum::EMAIL->value,
        ] as $settingKey => $fieldKey) {
            if ($missingAsDefault && in_array($settingKey, $this->undefinedBindingLimitKeys, true)) {
                // 執行期沒有這個定義就讀不到組別值，組別舊值不採用
                $limit = self::LEGACY_BINDING_LIMIT_DEFAULT;
                $source = '（查無設定定義，用預設值）';
            } else {
                [$value, $note] = $this->cachedDominantValue($dominantCache, $orgId, $settingKey);
                if ($note !== null) {
                    $warnings["{$orgId}-{$settingKey}"] = "組織 {$orgId} #{$settingKey}：{$note}";
                }
                $useDefault = $value === null && $missingAsDefault;
                if (!$useDefault && ($value === null || (int) $value < 1)) {
                    $this->line("  #{$settingKey} → {$fieldKey}：無有效舊值，略過");

                    continue;
                }

                $limit = $useDefault ? self::LEGACY_BINDING_LIMIT_DEFAULT : (int) $value;
                $source = $useDefault ? '（查無舊設定，用預設值）' : '';
            }

            $filled++;

            $query = DB::table('agent_register_limits')
                ->where('agent_group_id', $groupId)
                ->where('currency_id', self::CURRENCY_ID)
                ->where('field_key', $fieldKey);

            if (!$force) {
                // 只回填仍為初始狀態（未啟用且未設值）的列，不覆蓋人工設定
                $query->where('platform_enabled', 0)->whereNull('platform_max_limit');
            }

            $rows = $dryRun ? $query->count() : $query->update([
                'platform_enabled' => 1,
                'platform_max_limit' => $limit,
                'updated_at' => $now,
            ]);

            $this->line("  #{$settingKey} → {$fieldKey}：回填上限 {$limit}{$source}，" . $this->rowsLabel($dryRun, $rows));
        }

        return $filled;
    }

    /**
     * 回填推薦碼必填：#9107（0 = 不允許空 = 必填）。回傳 1 = 該組織需必填、0 = 維持可空。
     *
     * @param  array<string, array{0: ?string, 1: ?string}>  $dominantCache
     * @param  array<string, string>  $warnings
     */
    private function backfillReferralRequired(
        int $orgId,
        int $groupId,
        array &$dominantCache,
        array &$warnings,
        bool $dryRun,
        bool $force,
        mixed $now,
    ): int {
        $referralKey = AgentCustomizedSettingKeyEnum::REGISTRATION_ALLOW_CODE_EMPTY;
        [$allowEmpty, $note] = $this->cachedDominantValue($dominantCache, $orgId, $referralKey);
        if ($note !== null) {
            $warnings["{$orgId}-{$referralKey}"] = "組織 {$orgId} #{$referralKey}：{$note}";
        }

        // 舊值未設定時預設視為「允許空」（與切換前 code 的 ?? 1 一致）
        $required = $allowEmpty !== null && (int) $allowEmpty === 0;

        if (!$required) {
            $this->line("  #{$referralKey} → referral_code：維持 可空");

            return 0;
        }

        $query = DB::table('agent_member_field_settings')
            ->where('agent_group_id', $groupId)
            ->where('currency_id', self::CURRENCY_ID)
            ->where('page_type', self::PAGE_TYPE_REGISTER)
            ->where('field_key', MemberFieldKeyEnum::REFERRAL_CODE->value);

        if (!$force) {
            // 僅回填仍為初始狀態者:status 已被人工關閉(停用邀請碼欄位)不重新開啟
            $query->where('is_required', 0)->where('status', 1);
        }

        $rows = $dryRun ? $query->count() : $query->update([
            'status' => 1,
            'is_required' => 1,
            'updated_at' => $now,
        ]);

        $this->line("  #{$referralKey} → referral_code：回填 狀態開+必填，" . $this->rowsLabel($dryRun, $rows));

        return 1;
    }

    /** dry-run 印「將寫入」、正式執行印「已寫入」，列數依同一組條件計得 */
    private function rowsLabel(bool $dryRun, int $rows): string
    {
        return ($dryRun ? '將寫入' : '已寫入') . " {$rows} 列";
    }

    /**
     * 同組織同設置只查一次（同組織可有多個 type=6 組別）。
     *
     * @param  array<string, array{0: ?string, 1: ?string}>  $cache
     * @return array{0: ?string, 1: ?string}
     */
    private function cachedDominantValue(array &$cache, int $orgId, int $settingKey): array
    {
        $key = "{$orgId}-{$settingKey}";

        return $cache[$key] ??= $this->dominantSettingValue($orgId, $settingKey);
    }

    /**
     * 取該組織某個性化設置的「主值」：依組別下的代理數排序取最多者。
     *
     * @return array{0: ?string, 1: ?string} [主值, 值不一致時的警告訊息]
     */
    private function dominantSettingValue(int $orgId, int $settingKey): array
    {
        $rows = DB::table('agent_customized_settings as acs')
            ->join('agent_groups as ag', 'ag.id', '=', 'acs.agent_group_id')
            ->leftJoin('agents as a', 'a.customized_setting_group_id', '=', 'acs.agent_group_id')
            ->where('ag.organization_id', $orgId)
            ->where('acs.setting_key', $settingKey)
            ->groupBy('acs.value')
            ->orderByRaw('COUNT(a.id) DESC')
            ->get([DB::raw('acs.value as value'), DB::raw('COUNT(a.id) as agents')]);

        if ($rows->isEmpty()) {
            return [null, null];
        }

        $dominant = $rows->first();

        if ($rows->count() === 1) {
            return [(string) $dominant->value, null];
        }

        $others = $rows->slice(1)
            ->map(fn ($r) => "{$r->value}({$r->agents} 代理)")
            ->implode('、');

        return [
            (string) $dominant->value,
            "主值 {$dominant->value}（{$dominant->agents} 代理）；被忽略的值：{$others}",
        ];
    }
}
