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
        SchemaMonitor::create('withdrawal_orders', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('bank_id')->default(0)->index()->comment('banks.id');
            $table->string('bank_name', 50)->comment('銀行名稱');
            $table->string('bank_code', 16)->comment('銀行代碼');
            $table->string('account_name', 50)->comment('帳戶名稱');
            $table->string('account_no', 191)->default('')->comment('銀行帳戶');
            $table->integer('agent_group_id')->default(0)->index()->comment('金流商戶資料編號');
            $table->integer('agent_id')->default(0)->index()->comment('withdrawal_order_agents.id');
            $table->integer('member_id')->index()->comment('帳號系統編號(Members)');
            $table->decimal('amount', 18, 4)->comment('金額');
            $table->decimal('rate', 14, 9)->default(1)->comment('匯率(2025/05/05提升至9位)');
            $table->decimal('point', 18, 4)->default(0)->comment('平台點數');
            $table->decimal('tax', 11, 5)->default(0)->comment('手續費');
            $table->string('third_party_order_no', 50)->comment('三方金流訂單編號');
            $table->text('third_party_response')->nullable()->comment('三方API的response');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態 0:訂單建立,1:待審核,2:拒絕,3:等待付款,4:已付款,9:退回,21:代付請求中,22:代付處理中,24:已付款(代付),29:代付回應失敗');
            $table->dateTime('check_time')->nullable()->comment('審核時間');
            $table->dateTime('audit_time')->nullable();
            $table->integer('audit_operator')->nullable();
            $table->dateTime('payment_time')->nullable()->comment('付款時間');
            $table->integer('payment_operator')->nullable();
            $table->text('memo')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
            $table->integer('operator')->default(0)->comment('審核人');
            $table->text('change_logs')->comment('修改歷程');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('withdrawal_orders');
    }
};
