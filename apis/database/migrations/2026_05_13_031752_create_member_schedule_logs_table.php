<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'member_schedule_logs';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('玩家類別異動紀錄');
            $table->increments('id')->comment('系統編號');
            $table->integer('schedule_type_id')->default(0)->index('idx_member_schedule_logs_schedule_type_id')->comment('排程類型系統編號(schedule_types)');
            $table->integer('member_id')->index('idx_member_schedule_logs_member_id')->comment('玩家系統編號(Members)');
            $table->string('data_value', 50)->comment('資料內容');
            $table->tinyInteger('status')->default(0)->index('idx_member_schedule_logs_status')->comment('變更狀態 0:待處理,1:已處理,2:取消,9:處理失敗');
            $table->text('remark')->nullable()->comment('備註');
            $table->text('memo')->nullable()->comment('技術記錄用');
            $table->timestamp('created_at')->useCurrent()->index('idx_member_schedule_logs_created_at')->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(51)->comment('更新者ID');
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
