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
        SchemaMonitor::create('w_jackpot_reward_item_names', function (Blueprint $table) {
            $table->comment('W-Jackpot奖励项目-多语言');
            $table->increments('id');
            $table->integer('language_id')->index();
            $table->integer('w_jackpot_reward_item_id')->index();
            $table->string('name', 50);
            $table->string('prize_name', 50)->nullable()->default('')->comment('獎項名稱');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('w_jackpot_reward_item_names');
    }
};
