<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_group_bank_accounts', function (Blueprint $table) {
            $table->comment('會員群組自助存款可用帳號白名單(pivot)');
            $table->increments('id');
            $table->integer('member_group_id')->index()->comment('會員群組(member_groups.id)');
            $table->integer('bank_account_id')->comment('自助存款帳號(bank_accounts.id)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->unique(['member_group_id', 'bank_account_id'], 'member_group_bank_accounts_group_account_unique');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_group_bank_accounts');
    }
};
