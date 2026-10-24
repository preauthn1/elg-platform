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
        SchemaMonitor::create('summary_rolling_chip_records', function (Blueprint $table) {
            $table->increments('id');
            $table->dateTime('summary_time')->index()->comment('時間');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->decimal('pay_rolling_chip', 18, 4)->default(0)->comment('派發金額');
            $table->decimal('use_rolling_chip', 18, 4)->default(0)->comment('消耗金額');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('summary_rolling_chip_records');
    }
};
