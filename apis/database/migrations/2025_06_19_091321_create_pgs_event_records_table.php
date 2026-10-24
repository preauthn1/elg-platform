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
        SchemaMonitor::create('pgs_event_records', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('bet_id', 191)->default('0')->unique()->comment('注单唯一Id, ThemeId');
            $table->integer('event_id')->default(0)->index();
            $table->string('username', 30)->index()->comment('玩家帳號');
            $table->dateTime('pay_time')->index()->comment('派獎時間(utc)');
            $table->decimal('prize_amount', 18, 4)->default(0)->comment('獎勵金額');
            $table->string('info', 1000)->comment('response data');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('pgs_event_records');
    }
};
