<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * 鏡像表回填進度：SP_Backfill_Mirror_Step 依此由大到小分段回填 {table}_mirror。
     * 建置流程見 SchemaOperationService 與 docs/reconcile/payout-time-鏡像表建置.md。
     */
    public function up(): void
    {
        SchemaMonitor::create('mirror_backfill_state', function (Blueprint $table) {
            $table->string('src_table', 64)->primary()->comment('來源資料表名');
            $table->unsignedBigInteger('next_id')->comment('回填水位，由大到小走');
            $table->unsignedBigInteger('min_id')->default(0)->comment('回填下限 id（不含），走到這就停；0=補整張表');
            $table->boolean('done')->default(false)->comment('回填是否完成');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('mirror_backfill_state');
    }
};
