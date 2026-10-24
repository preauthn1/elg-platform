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
        SchemaMonitor::create('member_activities', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('data_time')->useCurrentOnUpdate()->useCurrent()->index()->comment('統計時間');
            $table->integer('member_id')->index()->comment('會員編號');
            $table->timestamp('last_login_time')->nullable()->index()->comment('最後登入時間');
            $table->timestamp('last_deposit_time')->nullable()->index()->comment('最後存款時間');
            $table->timestamp('last_withdrawal_time')->nullable()->index()->comment('最後提款時間');
            $table->timestamp('last_bet_time')->nullable()->index()->comment('最後投注時間');

            $table->unique(['data_time', 'member_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_activities');
    }
};
