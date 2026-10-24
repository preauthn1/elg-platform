<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 4180：代理數據看板要排在「數據分析」正後方。
     *
     * 選單排序為 `parent, sort, id`（見 API_Agent `AgentMenuController`），而統計報表查詢底下
     * 「數據分析」與三張輸贏排行報表原本同為 `sort = 0`、僅靠 id 決定先後，無法把 id 較大的
     * 新選單插進中間。故給這個群組明確序號：數據分析 1、代理數據看板 2、三張排行報表 3。
     * 群組內的**可見順序完全不變**，只是把新選單插進「數據分析」之後。
     *
     * SyncAgentMenuSeeder 只新增不更新既有列，既有環境的排序調整由本 migration 落地
     * （與 data/agent_menus.php 同步）。
     */
    private const array SORTS = [
        'data_analysis' => [0, 1],
        'report_agent_dashboard' => [240, 2],
        'member_win_lose_ranking' => [0, 3],
        'agent_win_lose_ranking' => [0, 3],
        'team_win_lose_ranking' => [0, 3],
    ];

    public function up(): void
    {
        foreach (self::SORTS as $code => [$from, $to]) {
            DB::table('agent_menus')->where('code', $code)->where('sort', $from)->update(['sort' => $to]);
        }
    }

    public function down(): void
    {
        foreach (self::SORTS as $code => [$from, $to]) {
            DB::table('agent_menus')->where('code', $code)->where('sort', $to)->update(['sort' => $from]);
        }
    }
};
