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
        SchemaMonitor::create('play8oy_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('transaction_id', 191)->default('')->comment('外部交易Id(OperationMethod＝3此欄位為空值)');
            $table->integer('type')->index()->comment('類型; OperationMethod; 1:開分,2:洗分,3:紅包(福袋、新年禮物)');
            $table->string('username', 30)->comment('玩家帳號');
            $table->decimal('trans_amount', 18, 4)->comment('交易金額');
            $table->dateTime('transaction_time')->index()->comment('交易時間(utc)');
            $table->integer('status')->index()->comment('狀態; Result 0:未處理,1:成功,2:失敗');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('最後修改人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('play8oy_transactions');
    }
};
