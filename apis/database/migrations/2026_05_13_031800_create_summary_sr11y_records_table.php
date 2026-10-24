<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'summary_sr11y_records';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->dateTime('summary_time')->index('idx_summary_sr11y_record_summary_time')->comment('時間');
            $table->dateTime('data_time')->index('idx_summary_sr11y_record_data_time')->comment('數據時間');
            $table->integer('member_id')->index('idx_summary_sr11y_record_member_id')->comment('會員系統編號');
            $table->tinyInteger('member_type')->default(0)->index('idx_summary_sr11y_record_member_type')->comment('會員類別');
            $table->integer('vendor_id')->index('idx_summary_sr11y_record_vendor_id')->comment('0~1000:遊戲商, 1001:summary_event_records.id+1000');
            $table->integer('game_id')->default(0)->comment('遊戲系統編號');
            $table->integer('game_type_id')->default(0)->index('idx_summary_sr11y_record_game_type_id')->comment('遊戲類型編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注金額');
            $table->decimal('win_lose', 18, 4)->default(0)->comment('贏得金額');
            $table->decimal('rolling_chip', 18, 4)->default(0)->comment('禮碼');
            $table->decimal('commission', 18, 4)->nullable()->default(0)->comment('佣金');
            $table->decimal('jackpot', 18, 4)->default(0)->comment('彩金');
            $table->integer('record_count')->default(0)->comment('資料計數');
            $table->decimal('bak', 18, 4)->default(0)->comment('備用');
            $table->decimal('bak1', 18, 4)->default(0)->comment('備用');
            $table->decimal('percentage')->nullable()->comment('分成百分比');
            $table->decimal('share_amount')->nullable()->comment('分成金額');
            $table->decimal('v_jackpot', 18, 4)->default(0)->comment('v_jackpot');
            $table->decimal('cost', 18, 4)->default(0)->comment('成本');
            $table->tinyInteger('correction')->default(0)->comment('改正標示');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->primary(['id', 'summary_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};
