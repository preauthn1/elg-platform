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
        SchemaMonitor::create('prizes', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('event_id')->index()->comment('活動編號');
            $table->integer('type_id')->comment('獎勵遊戲類型編號');
            $table->string('name', 191)->comment('獎勵活動名稱');
            $table->string('background', 191)->comment('背景圖');
            $table->integer('number')->comment('獎勵遊戲獲得次數');
            $table->boolean('is_repeatedly')->nullable()->default(false)->comment('可否重覆獲獎0:不可,1:可以');
            $table->integer('flow_limit_multiple')->default(0)->comment('稽核流水倍數');
            $table->decimal('max_point', 10)->default(0)->comment('最大中獎金額');
            $table->tinyInteger('withdrawal_cancel_reward')->default(0)->comment('發紅包與洗分取消領獎限制');
            $table->tinyInteger('cancel_other_reward')->default(0)->comment('領獎後取消其他同訂單活動獎勵');
            $table->text('info')->nullable()->comment('其他相關設定');
            $table->dateTime('expire_date')->index()->comment('獎勵遊戲次數到期日');
            $table->dateTime('draw_date')->nullable()->comment('摸彩卷的開獎日期');
            $table->boolean('status')->comment('狀態0:停用,1:啟用');
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
        SchemaMonitor::dropIfExists('prizes');
    }
};
