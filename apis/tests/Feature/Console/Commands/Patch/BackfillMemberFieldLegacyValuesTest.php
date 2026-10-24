<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Patch;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\MemberFieldKeyEnum;
use App\Enums\MemberFieldPageTypeEnum;
use App\Models\AgentSettingDefine;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * patch:backfill-member-field-legacy-values 的 --missing-as-default 與 --only-binding-limits（target/tickets#4459）。
 *
 * 守的是：組織查無舊綁定上限設定時，帶選項才以 1 回填；不帶選項維持略過；已人工設定的列不被覆蓋；
 * 只補綁定上限時不動推薦碼必填；設定定義表沒有該 key 時不採組別舊值；dry-run 不寫入。
 * 測試自建組織，除個別測試自行寫入的舊個性化設置外，該組織的 #2013／#2016 一定查無值。
 *
 * php artisan test tests/Feature/Console/Commands/Patch/BackfillMemberFieldLegacyValuesTest.php
 */
class BackfillMemberFieldLegacyValuesTest extends TestCase
{
    private const string COMMAND = 'patch:backfill-member-field-legacy-values';

    private int $orgId;

    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgId = (int) DB::table('organizations')->insertGetId([
            'name' => 'org-' . bin2hex(random_bytes(4)),
            'currency_id' => 1,
            'memo' => 'backfill legacy test',
        ]);

        $this->groupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => AgentGroupTypeEnum::MEMBER_FIELD_SETTING->value,
            'organization_id' => $this->orgId,
            'name' => 'backfill legacy test',
            'memo' => '',
            'is_default' => 0,
        ]);

        foreach (['phone', 'email'] as $field) {
            DB::table('agent_register_limits')->insert([
                'agent_group_id' => $this->groupId,
                'currency_id' => 1,
                'field_key' => $field,
                'platform_enabled' => 0,
                'platform_max_limit' => null,
                'custom_enabled' => 0,
                'admin' => 0,
            ]);
        }

        // 只回填有代理綁定的組別
        $this->createAgentAndDomain([
            'organization_id' => $this->orgId,
            'member_field_setting_group_id' => $this->groupId,
        ]);
    }

    /** 在本測試的組織建一個個性化組別，寫入一筆舊個性化設置 */
    private function insertLegacySetting(int $settingKey, string $value): void
    {
        $customizedGroupId = (int) DB::table('agent_groups')->insertGetId([
            'agent_group_type' => AgentGroupTypeEnum::CUSTOMIZED_SETTING->value,
            'organization_id' => $this->orgId,
            'name' => 'backfill legacy customized',
            'memo' => '',
            'is_default' => 0,
        ]);
        DB::table('agent_customized_settings')->insert([
            'agent_group_id' => $customizedGroupId,
            'setting_key' => $settingKey,
            'sub_category' => '',
            'value' => $value,
            'memo' => '',
        ]);
    }

    /** @return array{0: int, 1: ?int} [platform_enabled, platform_max_limit] */
    private function limitOf(string $field): array
    {
        $row = DB::table('agent_register_limits')
            ->where('agent_group_id', $this->groupId)
            ->where('currency_id', 1)
            ->where('field_key', $field)
            ->first();

        return [(int) $row->platform_enabled, $row->platform_max_limit === null ? null : (int) $row->platform_max_limit];
    }

    #[TestDox('帶 --missing-as-default：查無舊值的手機與信箱上限都回填成 1')]
    public function test_missing_legacy_value_is_backfilled_with_default(): void
    {
        $this->artisan(self::COMMAND, ['--missing-as-default' => true])->assertSuccessful();

        $this->assertSame([1, 1], $this->limitOf('phone'));
        $this->assertSame([1, 1], $this->limitOf('email'));
    }

    #[TestDox('不帶選項：查無舊值照舊略過，新表維持未啟用')]
    public function test_missing_legacy_value_is_skipped_without_option(): void
    {
        $this->artisan(self::COMMAND)->assertSuccessful();

        $this->assertSame([0, null], $this->limitOf('phone'));
        $this->assertSame([0, null], $this->limitOf('email'));
    }

    #[TestDox('帶 --missing-as-default：已人工設定的列不被覆蓋')]
    public function test_manually_configured_row_is_not_overwritten(): void
    {
        DB::table('agent_register_limits')
            ->where('agent_group_id', $this->groupId)
            ->where('field_key', 'email')
            ->update(['platform_enabled' => 1, 'platform_max_limit' => 3]);

        $this->artisan(self::COMMAND, ['--missing-as-default' => true])->assertSuccessful();

        $this->assertSame([1, 3], $this->limitOf('email'));
        $this->assertSame([1, 1], $this->limitOf('phone'));
    }

    #[TestDox('帶 --missing-as-default：該庫沒有信箱上限的設定定義時，組別舊值 5 不採用，回填 1')]
    public function test_undefined_setting_key_ignores_group_value(): void
    {
        $this->assertFalse(
            AgentSettingDefine::query()->where('setting_id', AgentCustomizedSettingKeyEnum::EMAIL_BIND_LIMIT)->exists(),
            '前提：測試庫沒有 #2016 的設定定義',
        );
        $this->insertLegacySetting(AgentCustomizedSettingKeyEnum::EMAIL_BIND_LIMIT, '5');

        $this->artisan(self::COMMAND, ['--missing-as-default' => true, '--only-binding-limits' => true])
            ->expectsOutputToContain('查無設定定義，用預設值')
            ->assertSuccessful();

        $this->assertSame([1, 1], $this->limitOf('email'));
    }

    #[TestDox('帶 --dry-run：只計數不寫入，輸出將寫入 1 列')]
    public function test_dry_run_does_not_write(): void
    {
        $this->artisan(self::COMMAND, ['--missing-as-default' => true, '--only-binding-limits' => true, '--dry-run' => true])
            ->expectsOutputToContain('將寫入 1 列')
            ->assertSuccessful();

        $this->assertSame([0, null], $this->limitOf('phone'));
        $this->assertSame([0, null], $this->limitOf('email'));
    }

    #[TestDox('帶 --only-binding-limits：舊值要求推薦碼必填時，推薦碼那列也不動')]
    public function test_only_binding_limits_leaves_referral_untouched(): void
    {
        // 舊個性化設置 #9107 = 0（不允許邀請碼為空＝必填）
        $this->insertLegacySetting(AgentCustomizedSettingKeyEnum::REGISTRATION_ALLOW_CODE_EMPTY, '0');
        DB::table('agent_member_field_settings')->insert([
            'agent_group_id' => $this->groupId,
            'currency_id' => 1,
            'page_type' => MemberFieldPageTypeEnum::REGISTER->value,
            'field_key' => MemberFieldKeyEnum::REFERRAL_CODE->value,
            'is_required' => 0,
            'status' => 1,
        ]);

        $this->artisan(self::COMMAND, ['--missing-as-default' => true, '--only-binding-limits' => true])->assertSuccessful();

        $isRequired = DB::table('agent_member_field_settings')
            ->where('agent_group_id', $this->groupId)
            ->where('field_key', MemberFieldKeyEnum::REFERRAL_CODE->value)
            ->value('is_required');
        $this->assertSame(0, (int) $isRequired);
        $this->assertSame([1, 1], $this->limitOf('email'));
    }
}
