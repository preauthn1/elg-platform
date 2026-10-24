<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `2026_10_07_000001_move_active_member_settings_to_agent_backend_setting`＝把 7006／7007
 * 「判斷活躍玩家條件」的 parent 從代理模式設置 (7000) 搬到代理後台設置 (5000)，target/tickets#4121。
 *
 * migration 只做 DML、不含 DDL，TestCase 的 DatabaseTransactions 能確實回滾，
 * 所以直接以 updateOrInsert 覆寫成已知狀態，不另做清理。
 */
class MoveActiveMemberSettingsToAgentBackendSettingTest extends TestCase
{
    private const array MOVED_IDS = [7006, 7007];

    /** 同在 7000 底下、不該被搬動的設定 */
    private const array UNTOUCHED_IDS = [7001, 7002, 7003];

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require base_path(
            'database/migrations/2026_10_07_000001_move_active_member_settings_to_agent_backend_setting.php'
        );

        foreach ([...self::MOVED_IDS, ...self::UNTOUCHED_IDS] as $settingId) {
            $this->seedDefine($settingId, 7000);
        }
    }

    private function seedDefine(int $settingId, int $parent): void
    {
        DB::table('agent_setting_defines')->updateOrInsert(
            ['setting_id' => $settingId],
            [
                'parent' => $parent,
                'type' => 3,
                'is_visible' => 1,
                'agent_edit' => 0,
                'memo' => "memo_{$settingId}",
                'language_text_code' => "code_{$settingId}",
                'default_value' => "default_{$settingId}",
                'data_type' => 'string',
                'is_data_required' => 0,
                'enable_agents' => null,
                'sort' => 0,
                'created_by' => 1,
            ],
        );
    }

    private function parentOf(int $settingId): ?int
    {
        $parent = DB::table('agent_setting_defines')->where('setting_id', $settingId)->value('parent');

        return $parent === null ? null : (int) $parent;
    }

    public function test_up_moves_parent_from_7000_to_5000(): void
    {
        $this->migration->up();

        foreach (self::MOVED_IDS as $settingId) {
            $this->assertSame(5000, $this->parentOf($settingId), "{$settingId} 應掛到代理後台設置");
        }
    }

    public function test_down_restores_parent_to_7000(): void
    {
        $this->migration->up();
        $this->migration->down();

        foreach (self::MOVED_IDS as $settingId) {
            $this->assertSame(7000, $this->parentOf($settingId), "{$settingId} down 後應回到代理模式設置");
        }
    }

    /** 全新環境還沒有這兩列（之後由 seeder 依 data 檔直接以 5000 新增），up 必須是 no-op。 */
    public function test_up_is_noop_when_rows_do_not_exist(): void
    {
        DB::table('agent_setting_defines')->whereIn('setting_id', self::MOVED_IDS)->delete();

        $this->migration->up();

        $this->assertSame(0, DB::table('agent_setting_defines')->whereIn('setting_id', self::MOVED_IDS)->count());
    }

    /** 條件帶上原 parent：被人手動調到其他區塊的列不該被覆蓋。 */
    public function test_up_skips_rows_not_under_7000(): void
    {
        $this->seedDefine(7006, 9999);

        $this->migration->up();

        $this->assertSame(9999, $this->parentOf(7006));
        $this->assertSame(5000, $this->parentOf(7007));
    }

    public function test_other_settings_under_7000_are_untouched(): void
    {
        $this->migration->up();

        foreach (self::UNTOUCHED_IDS as $settingId) {
            $this->assertSame(7000, $this->parentOf($settingId), "{$settingId} 不在搬移範圍");
        }
    }

    /** 只動 parent：setting_id 不改號（agent_customized_settings 以此 id 存值），其餘欄位原樣保留。 */
    public function test_up_changes_parent_only(): void
    {
        $columns = ['setting_id', 'type', 'is_visible', 'agent_edit', 'memo', 'language_text_code',
            'default_value', 'data_type', 'is_data_required', 'enable_agents', 'sort', 'created_by'];
        $before = DB::table('agent_setting_defines')
            ->whereIn('setting_id', self::MOVED_IDS)
            ->orderBy('setting_id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->migration->up();

        $after = DB::table('agent_setting_defines')
            ->whereIn('setting_id', self::MOVED_IDS)
            ->orderBy('setting_id')
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();

        $this->assertCount(2, $after);
        $this->assertSame($before, $after);
    }
}
