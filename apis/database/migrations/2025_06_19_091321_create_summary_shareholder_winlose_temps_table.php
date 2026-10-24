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
        SchemaMonitor::create('summary_shareholder_winlose_temps', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->dateTime('summary_time')->index()->comment('彙總時間(UTC)');
            $table->integer('agent_id')->index()->comment('代理ID');
            $table->integer('shareholder_category_id')->index()->comment('份桶類別ID');
            $table->decimal('turnover', 18, 4)->default(0)->comment('營業額');
            $table->decimal('winlose', 18, 4)->default(0)->comment('輸贏');
            $table->decimal('game_spending', 18, 4)->default(0)->comment('遊戲支出');
            $table->decimal('game_profit', 18, 4)->default(0)->comment('遊戲盈利');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['summary_time', 'agent_id', 'shareholder_category_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_shareholder_winlose_temps');
    }
};
