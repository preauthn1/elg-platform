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
        SchemaMonitor::create('refresh_ranking_transaction_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('member_id')->index()->comment('members.id');
            $table->integer('type')->comment('類別');
            $table->decimal('before_balance', 18, 4)->default(0)->comment('更改前金額');
            $table->decimal('amount', 18, 4)->default(0)->comment('變更金額');
            $table->decimal('after_balance', 18, 4)->default(0)->comment('更改後金額');
            $table->integer('status')->default(0)->comment('更新者ID');
            $table->string('remark', 191)->comment('備註');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('refresh_ranking_transaction_logs');
    }
};
