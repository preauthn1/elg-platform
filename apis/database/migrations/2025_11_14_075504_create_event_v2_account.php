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
        SchemaMonitor::create('event_v2_accounts', function (Blueprint $table) {
            $table->id();
            $table->integer('vendor_id')->index()->comment('遊戲商id');
            $table->integer('account_id')->index()->comment('遊戲帳號id');
            $table->integer('event_v2_user_join_id')->index()->comment('參加活動id');
            $table->timestamps();

            // 索引
            $table->unique(['vendor_id', 'account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_v2_accounts');
    }
};
