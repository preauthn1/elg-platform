<?php

declare(strict_types=1);

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * target/tickets#4121：「判斷活躍玩家條件(充值／有效流水)」(7006／7007) 在個性化組別設定頁要改放到
     * 「代理後台設置」區塊。區塊歸屬只看 agent_setting_defines.parent，所以把 parent 從 7000 改成 5000。
     *
     * 為什麼要 migration 而不是只改 data 檔：SyncAgentSettingDefinesSeeder 只新增不更新既有列，
     * 各品牌上線後早已有這兩列且 parent=7000，只改 data/agent_setting_defines.php 不會生效。
     * setting_id 不能改號（agent_customized_settings 存有設定值、GGR 結算以此 id 取值），只動 parent。
     *
     * 條件帶上原 parent，重跑冪等，也不會覆蓋被人手動調整過的列；列不存在時（全新環境）為 no-op，
     * 由 seeder 依 data 檔直接以 parent=5000 新增。updated_at 由欄位的 ON UPDATE CURRENT_TIMESTAMP 帶入。
     */
    private const array SETTING_IDS = [
        AgentCustomizedSettingKeyEnum::ACTIVE_MEMBER_DEPOSIT_AMOUNT,
        AgentCustomizedSettingKeyEnum::ACTIVE_MEMBER_VALID_BET_AMOUNT,
    ];

    /** 代理模式設置 */
    private const int FROM_PARENT = 7000;

    /** 代理後台設置 */
    private const int TO_PARENT = 5000;

    public function up(): void
    {
        DB::table('agent_setting_defines')
            ->whereIn('setting_id', self::SETTING_IDS)
            ->where('parent', self::FROM_PARENT)
            ->update(['parent' => self::TO_PARENT]);
    }

    public function down(): void
    {
        DB::table('agent_setting_defines')
            ->whereIn('setting_id', self::SETTING_IDS)
            ->where('parent', self::TO_PARENT)
            ->update(['parent' => self::FROM_PARENT]);
    }
};
