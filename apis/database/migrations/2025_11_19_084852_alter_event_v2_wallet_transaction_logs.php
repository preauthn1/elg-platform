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
        SchemaMonitor::table('event_v2_wallet_transaction_logs', function (Blueprint $table) {
            $table->integer('wallet_transaction_log_id')->nullable()->default(null)->comment('wallet_transaction_logs.id')->change();
            $table->integer('vendor_id')->nullable()->default(null)->comment('遊戲商id')->change();
            $table->integer('account_id')->nullable()->default(null)->comment('遊戲帳號id')->change();
            $table->string('account_username')->nullable()->default(null)->comment('遊戲帳號名稱')->change();
            $table->integer('event_v2_user_join_id')->nullable()->default(null)->comment('參加活動id')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('event_v2_wallet_transaction_logs', function (Blueprint $table) {
            $table->integer('wallet_transaction_log_id')->comment('wallet_transaction_logs.id')->change();
            $table->integer('vendor_id')->comment('遊戲商id')->change();
            $table->integer('account_id')->comment('遊戲帳號id')->change();
            $table->string('account_username')->comment('遊戲帳號名稱')->change();
            $table->integer('event_v2_user_join_id')->comment('參加活動id')->change();
        });
    }
};
