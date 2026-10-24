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
        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            $table->string('account_name', 191)->change()->comment('帳戶名稱');
            $table->string('account_no', 191)->change()->comment('帳戶帳號');
        }, 2000000);
        SchemaMonitor::table('member_bank_accounts', function (Blueprint $table) {
            $table->string('account_no', 191)->change()->comment('帳戶帳號');
        }, 2000000);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('withdrawal_orders', function (Blueprint $table) {
            $table->string('account_name', 50)->change()->comment('帳戶名稱');
            $table->string('account_no', 50)->change()->comment('帳戶帳號');
        }, 2000000);

        SchemaMonitor::table('member_bank_accounts', function (Blueprint $table) {
            $table->string('account_no', 50)->change()->comment('帳戶帳號');
        }, 2000000);
    }
};
