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
        SchemaMonitor::create('transaction_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('transaction_no', 191)->index()->comment('單號');
            $table->integer('agent_id')->nullable()->default(0)->comment('代理系統編號(同一個玩家可能由不同代理開洗分)');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->integer('vendor_id')->nullable()->default(0)->comment('遊戲商系統編號');
            $table->integer('order_id')->nullable()->default(0)->index()->comment('訂單系統編號');
            $table->integer('type')->index()->comment('類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:打卡獎勵,11:拒絕洗分,12:退還洗分,13:活動獎勵,14:補帳,15:登入活動獎勵,16:回收登入活動獎勵,17:全民代理,18: v-jackpot,19:首充活動返利,20:註冊送,21:綁定手機送,22:預付網扣款,23:預付網退款,24:充值活動返利,25:每日充值活動返利,26:邀請玩家獎勵活動返利,30:w-jackpot,31:W-Jackpot 6d獎勵\\n101~500:活動獎勵(101:slot comptition)');
            $table->decimal('point', 18, 4)->default(0)->comment('平台金額');
            $table->decimal('tax', 18, 4)->default(0)->comment('稅收');
            $table->decimal('vendor_point', 18, 4)->comment('Vendor金額');
            $table->decimal('rate', 10, 4)->comment('平台點數與遊戲點數的比例');
            $table->decimal('before_balance', 18, 4)->default(0)->comment('变更前数点');
            $table->decimal('balance', 18, 4)->default(0)->comment('平台餘額');
            $table->decimal('vendor_balance', 18, 4)->nullable()->default(0)->comment('Vendor餘額');
            $table->integer('status')->default(0)->comment('狀態編號');
            $table->string('remark', 191)->nullable()->index()->comment('備註');
            $table->string('checksum', 191)->nullable()->comment('校驗碼');
            $table->string('result', 191)->default('0')->comment('結果');
            $table->string('operator', 191)->index()->comment('操作者');
            $table->integer('platform')->default(0)->comment('操作平台0:前台，1:客服，2:代理後台');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');

            $table->index(['member_id', 'type', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('transaction_logs');
    }
};
