<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * GGR 條件標題同總代內唯一 工單4124
 *
 * 2026-09-01 定案（邊界8-12）：標題被 4121 下拉引用，重複會選錯，
 * 做唯一性檢查。應用層在 GgrConditionService::assertNameUnique，
 * 此索引為併發下的最後防線。上索引前先把既有重複列改名（附序號），
 * 避免 dev 已有資料時 migration 直接炸掉。
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('ggr_conditions')
            ->selectRaw('general_agent_id, name')
            ->groupBy('general_agent_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = DB::table('ggr_conditions')
                ->where('general_agent_id', $duplicate->general_agent_id)
                ->where('name', $duplicate->name)
                ->orderBy('id')
                ->pluck('id')
                ->slice(1)
                ->values();

            foreach ($ids as $index => $id) {
                DB::table('ggr_conditions')
                    ->where('id', $id)
                    ->update(['name' => mb_substr($duplicate->name, 0, 92) . ' (' . ($index + 2) . ')']);
            }
        }

        SchemaMonitor::table('ggr_conditions', function (Blueprint $table) {
            $table->unique(['general_agent_id', 'name'], 'uk_ggr_condition_agent_name');
        });
    }

    public function down(): void
    {
        SchemaMonitor::table('ggr_conditions', function (Blueprint $table) {
            $table->dropUnique('uk_ggr_condition_agent_name');
        });
    }
};
