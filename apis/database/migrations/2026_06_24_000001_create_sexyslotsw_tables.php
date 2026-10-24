<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('sexyslotsw_accounts', function (Blueprint $table) {
            $table->vendorAccountColumns(
                usernameLength: 50,
                uniqueUsername: true,
                withSessionToken: [REDACTED],
            );
        });

        SchemaMonitor::create('sexyslotsw_bet_records', function (Blueprint $table) {
            $table->vendorBetRecordColumns(
                uniqueBetId: true,
                withReferenceNo: false,
            );
        });

        SchemaMonitor::create('sexyslotsw_deal_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vendor_info_id')->default(0)->comment('遊戲商設定編號');
            $table->integer('member_id')->default(0)->comment('會員系統編號');
            $table->string('username', 100);
            $table->unsignedInteger('bet_record_id')->nullable()->comment('FK sexyslotsw_bet_records.id');
            $table->string('bet_id', 100)->comment('Tiger Games roundId');
            $table->string('reference_no', 100)->unique()->comment('Tiger Games transactionId — 冪等鍵');
            $table->string('action', 20)->comment('debit / credit');
            $table->decimal('amount', 18, 4)->default(0);
            $table->decimal('balance_before', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4)->nullable();
            $table->boolean('rollback')->default(false)->comment('是否為退款/correction');
            $table->boolean('endround')->default(false)->comment('回合結束標記');
            $table->json('info')->nullable()->comment('原始 callback payload');
            $table->timestamps();

            $table->index('vendor_info_id');
            $table->index('member_id');
            $table->index('username');
            $table->index('bet_id');
            $table->index('bet_record_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('sexyslotsw_deal_records');
        SchemaMonitor::dropIfExists('sexyslotsw_bet_records');
        SchemaMonitor::dropIfExists('sexyslotsw_accounts');
    }
};
