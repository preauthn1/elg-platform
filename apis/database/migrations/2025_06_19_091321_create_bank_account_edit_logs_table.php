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
        SchemaMonitor::create('bank_account_edit_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('account_id', 191)->default('0')->comment('bank_accounts.id');
            $table->decimal('before_amount', 18, 4)->default(0)->comment('初始金額');
            $table->decimal('after_amount', 18, 4)->default(0)->comment('變更後金額');
            $table->string('operator', 20)->comment('操作者');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('bank_account_edit_logs');
    }
};
