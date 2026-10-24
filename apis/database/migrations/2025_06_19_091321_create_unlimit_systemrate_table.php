<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('unlimit_systemrate', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->default(0)->comment('代理ID');
            $table->integer('agent_group_id')->default(0)->comment('無限代群組ID');
            $table->integer('level')->default(0)->comment('無限代理等級');
            $table->decimal('min_amount', 18, 4)->default(0)->comment('該級距金額下限');
            $table->decimal('max_amount', 18, 4)->default(0)->comment('該級距金額上限');
            $table->decimal('bonus_amount', 18, 4)->default(0)->comment('該級距獎勵金額');
            $table->decimal('rebate_rate', 18, 4)->default(0)->comment('返傭比例');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('unlimit_systemrate');
    }
};
