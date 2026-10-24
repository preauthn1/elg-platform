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
        SchemaMonitor::create('shareholder_category_settings', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('shareholder_category_id')->default(0)->index()->comment('份桶類別ID');
            $table->integer('vendor_id')->default(0)->index()->comment('遊戲商編號');
            $table->integer('game_type_id')->default(0)->index()->comment('遊戲類型ID');
            $table->boolean('calc_bet')->default(true)->comment('計算投注額');
            $table->boolean('calc_win_lose')->default(true)->comment('計算輸贏');
            $table->string('memo', 200)->nullable()->comment('備註用');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:disable, 1:enable');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_category_settings');
    }
};
