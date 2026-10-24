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
        SchemaMonitor::create('member_bank_accounts', function (Blueprint $table) {
            $table->comment('玩家銀行帳戶');
            $table->increments('id')->comment('PK流水號');
            $table->integer('member_id')->index()->comment('member_id');
            $table->integer('bank_id')->comment('關聯的銀行; order_types.id');
            $table->string('account_name', 100)->comment('帳號用戶名');
            $table->string('account_no', 50)->comment('帳號');
            $table->boolean('status')->default(false)->comment('狀態');
            $table->integer('sort')->default(0)->comment('排序');
            $table->integer('operator_cs')->comment('修改人(客服後台)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_bank_accounts');
    }
};
