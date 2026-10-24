<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * 4118：風控管理(31)需緊跟下線會員管理(30)之後；代理自助開洗分原本同為 30 會插在中間，往後挪到 32。
     * SyncAgentMenuSeeder 只新增不更新既有列，既有環境的排序調整由本 migration 落地（與 data/agent_menus.php 同步）。
     */
    public function up(): void
    {
        DB::table('agent_menus')
            ->where('code', 'agent_self_deposit_withdrawal')
            ->where('sort', 30)
            ->update(['sort' => 32]);
    }

    public function down(): void
    {
        DB::table('agent_menus')
            ->where('code', 'agent_self_deposit_withdrawal')
            ->where('sort', 32)
            ->update(['sort' => 30]);
    }
};
