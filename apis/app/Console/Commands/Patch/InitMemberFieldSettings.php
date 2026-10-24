<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\Member\PasswordCharRequirementEnum;
use App\Enums\MemberFieldKeyEnum;
use App\Enums\MemberFieldPageTypeEnum;
use App\Enums\MemberRegisterLimitFieldEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 初始化「會員註冊/登入欄位設置」與「註冊限制」。
 *
 * 為每個「有代理的組織」建立一個 type=6 的預設 agent_groups 組別，
 * 並 seed 預設欄位設定(agent_member_field_settings)與註冊限制(agent_register_limits)，
 * 最後把該組織所有 member_field_setting_group_id 為空的代理 backfill 指向該組別。
 *
 * 設計對照(與 customized_setting_group_id 同精神):
 *  - agent_groups(type=6) = 組別殼；agents.member_field_setting_group_id = 綁定；
 *    agent_member_field_settings / agent_register_limits = 實際設定值。
 *
 * 冪等性:
 *  - 組別 firstOrCreate(organization_id, type=6)；設定值 insertOrIgnore(unique key)；
 *    backfill 僅更新 member_field_setting_group_id IS NULL 的代理。重跑安全。
 *
 * 幣別:目前僅 MYR(currency_id=1),seed 一律寫入 MYR。
 *
 * 升級用法:
 *   php artisan patch:init-member-field-settings --dry-run
 *   php artisan patch:init-member-field-settings
 *
 * target/tickets#3970
 */
class InitMemberFieldSettings extends Command
{
    protected $signature = 'patch:init-member-field-settings
        {--dry-run : 只預覽將建立/更新的數量，不寫入}
        {--reconcile-login : 回歸校正:將所有 type=6 組別的登入欄位強制回預設(玩家帳號+手機號 皆啟用、密碼必要),覆蓋現有值;可重複執行}';

    protected $description = '初始化會員註冊/登入欄位設置與註冊限制(建立 type=6 組別、seed 預設、backfill 代理)';

    /** 會員欄位設置組別類型 */
    private const AGENT_GROUP_TYPE = 6;

    /** 幣別:MYR */
    private const CURRENCY_ID = 1;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('⚠️  DRY RUN:不寫入資料庫');
        }

        $reconcileLogin = (bool) $this->option('reconcile-login');
        if ($reconcileLogin) {
            $this->warn('🔧 回歸校正:登入欄位將強制回預設(玩家帳號+手機號 皆啟用、密碼必要),覆蓋現有值');
        }

        // 只處理「有代理」的組織，避免為停用/測試組織建空組別
        $orgIds = DB::table('agents')
            ->select('organization_id')
            ->distinct()
            ->orderBy('organization_id')
            ->pluck('organization_id')
            ->all();

        if ($orgIds === []) {
            $this->info('沒有任何代理，略過。');

            return self::SUCCESS;
        }

        $now = now()->toDateTimeString();
        $registerFields = $this->registerFieldDefaults();
        $loginFields = $this->loginFieldDefaults();
        $limitFields = $this->registerLimitDefaults();

        $createdGroups = 0;
        $seededFieldRows = 0;
        $seededLimitRows = 0;
        $backfilledAgents = 0;
        $reconciledLoginRows = 0;

        foreach ($orgIds as $orgId) {
            // 1. 取得/建立該組織的 type=6 預設組別
            $group = DB::table('agent_groups')
                ->where('organization_id', $orgId)
                ->where('agent_group_type', self::AGENT_GROUP_TYPE)
                ->first();

            if (!$group) {
                $createdGroups++;
                if (!$dryRun) {
                    $groupId = DB::table('agent_groups')->insertGetId([
                        'agent_group_type' => self::AGENT_GROUP_TYPE,
                        'organization_id' => $orgId,
                        'name' => '註冊/登入欄位設定&註冊限制',
                        'memo' => '系統預設組別',
                        'is_default' => $orgId === 1 ? 1 : 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $groupId = 0; // dry-run 無實際 id
                }
            } else {
                $groupId = $group->id;
            }

            if ($dryRun) {
                // dry-run 僅估算數量(組別若尚未建立則以預設筆數估)
                $seededFieldRows += count($registerFields) + count($loginFields);
                $seededLimitRows += count($limitFields);

                continue;
            }

            // 2+3. seed 欄位設定與註冊限制(缺漏才補)
            [$fieldCount, $limitCount] = $this->seedGroupRows($groupId, $now);
            $seededFieldRows += $fieldCount;
            $seededLimitRows += $limitCount;

            // 4. backfill 該組織 member_field_setting_group_id 為空的代理
            $backfilledAgents += DB::table('agents')
                ->where('organization_id', $orgId)
                ->whereNull('member_field_setting_group_id')
                ->update(['member_field_setting_group_id' => $groupId]);
        }

        // 5. 升級清理/補預設:驗證碼併入手機/信箱(刪舊獨立驗證碼欄位)、手機字數補預設。詳見各方法註解。
        $prunedVerifyCode = $dryRun ? 0 : $this->pruneVerifyCodeFields();
        $patchedMobile = $dryRun ? 0 : $this->patchMobileLengthDefaults();

        // 6. 回歸校正(--reconcile-login):掃「全部」type=6 組別(含額外/複製的空組別如 187/188),
        //    先補齊缺漏欄位/限制列(填滿空組別),再強制登入欄位回預設。可重複執行。
        if ($reconcileLogin) {
            $allGroupIds = DB::table('agent_groups')
                ->where('agent_group_type', self::AGENT_GROUP_TYPE)
                ->orderBy('id')
                ->pluck('id')
                ->all();

            foreach ($allGroupIds as $gid) {
                if (!$dryRun) {
                    [$fieldCount, $limitCount] = $this->seedGroupRows($gid, $now);
                    $seededFieldRows += $fieldCount;
                    $seededLimitRows += $limitCount;
                }
                $reconciledLoginRows += $this->reconcileLoginDefaults($gid, $loginFields, $now, $dryRun);
            }
        }

        $this->info(sprintf(
            '%s:組織 %d 個、新建組別 %d、欄位設定 %d 筆、註冊限制 %d 筆、backfill 代理 %d 個、清除舊驗證碼欄位 %d 筆、補手機字數預設 %d 筆。',
            $dryRun ? 'DRY RUN 完成' : '完成',
            count($orgIds),
            $createdGroups,
            $seededFieldRows,
            $seededLimitRows,
            $backfilledAgents,
            $prunedVerifyCode,
            $patchedMobile
        ));

        if ($reconcileLogin) {
            $this->info(sprintf(
                '回歸校正:掃全部 type=6 組別,登入欄位%s %d 筆。',
                $dryRun ? '將更新' : '已更新',
                $reconciledLoginRows
            ));
        }

        return self::SUCCESS;
    }

    /**
     * 升級清理:驗證碼已併入手機/信箱欄位(手機/信箱開啟即含驗證),不再獨立成欄位。
     * 刪除舊版遺留的 sms_verify_code / email_verify_code(insertOrIgnore 只補不刪,故需明確清除)。
     * 冪等:已刪即無;正式升級不清空整表也能讓呈現正確變更。
     */
    private function pruneVerifyCodeFields(): int
    {
        return DB::table('agent_member_field_settings')
            ->whereIn('field_key', ['sms_verify_code', 'email_verify_code'])
            ->delete();
    }

    /**
     * 升級補預設:手機號碼字數預設 7~15(升級前既有 mobile 的 extra_config 為空,insertOrIgnore
     * 不會更新已存在列,故明確補上)。僅補「min/max 皆空」者,不覆蓋代理已手動設定的值。
     */
    private function patchMobileLengthDefaults(): int
    {
        $patched = 0;
        $mobileRows = DB::table('agent_member_field_settings')
            ->where('field_key', 'mobile')
            ->where('page_type', MemberFieldPageTypeEnum::REGISTER->value)
            ->get(['id', 'extra_config']);

        foreach ($mobileRows as $mr) {
            $cfg = json_decode((string) $mr->extra_config, true) ?: [];
            if (($cfg['min_length'] ?? null) !== null || ($cfg['max_length'] ?? null) !== null) {
                continue;
            }
            $cfg['min_length'] = 7;
            $cfg['max_length'] = 15;
            DB::table('agent_member_field_settings')
                ->where('id', $mr->id)
                ->update(['extra_config' => json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
            $patched++;
        }

        return $patched;
    }

    private function fieldRow(int $groupId, int $pageType, array $f, string $now): array
    {
        return [
            'agent_group_id' => $groupId,
            'currency_id' => self::CURRENCY_ID,
            'page_type' => $pageType,
            'field_key' => $f['field_key'],
            'field_group' => $f['field_group'],
            'sort' => $f['sort'],
            'is_required' => $f['is_required'],
            'status' => $f['status'],
            'extra_config' => $f['extra_config'] === null ? null : json_encode($f['extra_config'], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
            'admin' => 0,
        ];
    }

    /**
     * 為單一組別 seed 欄位設定(註冊+登入)與註冊限制;僅補缺漏(insertOrIgnore),重跑安全。
     * 空組別(如後台另建/複製出的 187/188)會被補齊。
     *
     * @return array{0:int,1:int} [欄位新增筆數, 註冊限制新增筆數]
     */
    private function seedGroupRows(int $groupId, string $now): array
    {
        $fieldRows = [];
        foreach ($this->registerFieldDefaults() as $f) {
            $fieldRows[] = $this->fieldRow($groupId, MemberFieldPageTypeEnum::REGISTER->value, $f, $now);
        }
        foreach ($this->loginFieldDefaults() as $f) {
            $fieldRows[] = $this->fieldRow($groupId, MemberFieldPageTypeEnum::LOGIN->value, $f, $now);
        }
        $fieldCount = DB::table('agent_member_field_settings')->insertOrIgnore($fieldRows);

        $limitRows = [];
        foreach ($this->registerLimitDefaults() as $l) {
            $limitRows[] = [
                'agent_group_id' => $groupId,
                'currency_id' => self::CURRENCY_ID,
                'field_key' => $l['field_key'],
                'platform_enabled' => $l['platform_enabled'],
                'platform_max_limit' => $l['platform_max_limit'],
                'custom_enabled' => $l['custom_enabled'],
                'created_at' => $now,
                'updated_at' => $now,
                'admin' => 0,
            ];
        }
        $limitCount = DB::table('agent_register_limits')->insertOrIgnore($limitRows);

        return [$fieldCount, $limitCount];
    }

    /**
     * 回歸校正:強制某組別的登入欄位回預設(玩家帳號 + 手機號 皆啟用=帳號+手機號登入模式;密碼恆為必要)。
     * dry-run 時只計算會被變更的列數,不寫入。可重複執行(已符合則 0 筆)。
     */
    private function reconcileLoginDefaults(int $groupId, array $loginFields, string $now, bool $dryRun): int
    {
        if ($groupId === 0) {
            return 0;
        }

        $count = 0;
        foreach ($loginFields as $f) {
            // 只挑「與預設不同」的列,避免對已正確的列白改(不必要地 bump updated_at)
            $query = DB::table('agent_member_field_settings')
                ->where('agent_group_id', $groupId)
                ->where('currency_id', self::CURRENCY_ID)
                ->where('page_type', MemberFieldPageTypeEnum::LOGIN->value)
                ->where('field_key', $f['field_key'])
                ->where(function ($q) use ($f) {
                    $q->where('status', '!=', $f['status'])
                        ->orWhere('is_required', '!=', $f['is_required'])
                        ->orWhere('sort', '!=', $f['sort']);
                });

            if ($dryRun) {
                $count += $query->count();

                continue;
            }

            $count += $query->update([
                'is_required' => $f['is_required'],
                'status' => $f['status'],
                'sort' => $f['sort'],
                'updated_at' => $now,
            ]);
        }

        return $count;
    }

    /**
     * 註冊欄位預設(依前端註冊介面呈現:順序/啟用/必填)。
     * 啟用(status=1):玩家帳號、真實姓名、設定密碼、確認密碼、推廣碼；其餘停用(status=0)。
     * 鎖定(前端不可調整、DB 仍存值)欄位:username / last_name / password——預設 status=1 且 is_required=1。
     */
    private function registerFieldDefaults(): array
    {
        return [
            ['field_key' => MemberFieldKeyEnum::USERNAME->value, 'field_group' => 1, 'sort' => 1, 'is_required' => 1, 'status' => 1, 'extra_config' => ['min_length' => 4, 'max_length' => 10]],
            ['field_key' => MemberFieldKeyEnum::LAST_NAME->value, 'field_group' => 1, 'sort' => 2, 'is_required' => 1, 'status' => 1, 'extra_config' => null],
            ['field_key' => MemberFieldKeyEnum::PASSWORD->value, 'field_group' => 1, 'sort' => 3, 'is_required' => 1, 'status' => 1, 'extra_config' => ['min_length' => 6, 'max_length' => 12, 'rules' => ['alphabet' => PasswordCharRequirementEnum::REQUIRED->value, 'number' => PasswordCharRequirementEnum::REQUIRED->value, 'symbol' => PasswordCharRequirementEnum::OPTIONAL->value]]],
            ['field_key' => MemberFieldKeyEnum::CONFIRM_PASSWORD->value, 'field_group' => 1, 'sort' => 4, 'is_required' => 1, 'status' => 1, 'extra_config' => null],
            ['field_key' => MemberFieldKeyEnum::REFERRAL_CODE->value, 'field_group' => 1, 'sort' => 5, 'is_required' => 0, 'status' => 1, 'extra_config' => null],
            ['field_key' => MemberFieldKeyEnum::MOBILE->value, 'field_group' => 1, 'sort' => 6, 'is_required' => 0, 'status' => 0, 'extra_config' => ['min_length' => 7, 'max_length' => 15]],
            ['field_key' => MemberFieldKeyEnum::EMAIL->value, 'field_group' => 1, 'sort' => 7, 'is_required' => 0, 'status' => 0, 'extra_config' => null],
            ['field_key' => MemberFieldKeyEnum::BIRTHDAY->value, 'field_group' => 1, 'sort' => 8, 'is_required' => 0, 'status' => 0, 'extra_config' => null],
            // 手機/信箱驗證碼不再獨立成欄位:手機號欄位本身即含「填號 + 發碼 + 驗證」一體,
            // 信箱同理。驗證與否隨 mobile/email 欄位啟用,不需獨立 sms_verify_code/email_verify_code。
        ];
    }

    /**
     * 登入欄位預設。
     * 登入識別方式為「玩家帳號 / 手機號 / 帳號+手機號」三選一(至少開一個);密碼恆為必要、
     * 於設定頁隱藏、僅存於 DB(status=1)。預設:玩家帳號+手機號 皆啟用(帳號+手機號登入模式)。
     */
    private function loginFieldDefaults(): array
    {
        return [
            ['field_key' => MemberFieldKeyEnum::USERNAME->value, 'field_group' => 1, 'sort' => 1, 'is_required' => 1, 'status' => 1, 'extra_config' => null],
            ['field_key' => MemberFieldKeyEnum::MOBILE->value, 'field_group' => 1, 'sort' => 2, 'is_required' => 0, 'status' => 1, 'extra_config' => null],
            ['field_key' => MemberFieldKeyEnum::PASSWORD->value, 'field_group' => 1, 'sort' => 3, 'is_required' => 1, 'status' => 1, 'extra_config' => null],
        ];
    }

    /**
     * 註冊限制預設(6 個欄位,預設全關,由各白標後台自行開啟)
     */
    private function registerLimitDefaults(): array
    {
        return array_map(
            fn (MemberRegisterLimitFieldEnum $f) => [
                'field_key' => $f->value,
                'platform_enabled' => 0,
                'platform_max_limit' => null,
                'custom_enabled' => 0,
            ],
            MemberRegisterLimitFieldEnum::cases()
        );
    }
}
