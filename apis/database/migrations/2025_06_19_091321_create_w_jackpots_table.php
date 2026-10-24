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
        SchemaMonitor::create('w_jackpots', function (Blueprint $table) {
            $table->comment('W-Jackpot活動明細');
            $table->increments('id');
            $table->integer('event_id')->default(0)->index();
            $table->integer('event_detail_id')->default(0)->index()->comment('event_details.id');
            $table->integer('no')->default(0);
            $table->dateTime('draw_time')->nullable();
            $table->string('draw_number', 20)->nullable();
            $table->string('draw_number_2', 4)->nullable();
            $table->string('draw_number_3', 4)->nullable();
            $table->string('draw_number_4', 200)->nullable();
            $table->string('draw_number_5', 200)->nullable();
            $table->string('remark', 100)->nullable();
            $table->integer('status')->default(0);
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('w_jackpots');
    }
};
