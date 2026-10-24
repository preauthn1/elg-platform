<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('foreign_shareholder_category_settings')) {
            return;
        }

        SchemaMonitor::create('foreign_shareholder_category_settings', function (Blueprint $table) {
            $table->comment('模擬外部子站遊戲對應類別使用');
            $table->increments('id');
            $table->integer('category_id')->comment('份桶類別ID');
            $table->integer('vendor_id')->comment('遊戲商編號');
            $table->integer('game_type_id')->comment('遊戲類別id');
            $table->tinyInteger('calc_bet')->default(1)->comment('計算投注額');
            $table->tinyInteger('calc_win_lose')->default(1)->comment('計算輸贏');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();

            $table->index('category_id', 'idx_mars_shareholder_category_settings_sc_id');
            $table->index('vendor_id', 'idx_mars_shareholder_category_settings_vendor_id');
            $table->index('game_type_id', 'idx_mars_shareholder_category_settings_game_type_id');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('foreign_shareholder_category_settings');
    }
};
