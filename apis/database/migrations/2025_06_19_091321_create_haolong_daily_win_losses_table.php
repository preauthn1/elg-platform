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
        SchemaMonitor::create('haolong_daily_win_losses', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('draw_date')->index()->comment('開彩日期');
            $table->string('login_id', 191)->index()->comment('投注者帳號');
            $table->decimal('turnover', 18, 4)->comment('投注金額');
            $table->decimal('strike', 18, 4)->comment('中獎金額');
            $table->decimal('commission', 18, 4)->comment('佣金');
            $table->decimal('total', 18, 4)->comment('輸贏金額');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('haolong_daily_win_losses');
    }
};
