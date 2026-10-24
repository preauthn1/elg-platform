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
        SchemaMonitor::create('lucky_draws', function (Blueprint $table) {
            $table->comment('抽獎券活動明細');
            $table->increments('id')->comment('流水号');
            $table->integer('event_id')->default(0)->index()->comment('活動ID');
            $table->integer('event_detail_id')->default(0)->index()->comment('event_details.id');
            $table->integer('no')->default(0)->comment('期數');
            $table->dateTime('draw_time')->nullable()->comment('開獎時間');
            $table->string('draw_number', 20)->nullable()->comment('開獎號碼');
            $table->string('remark', 100)->nullable()->comment('備註');
            $table->integer('status')->default(0)->comment('狀態; 0:未開獎, 1:已開奬, 2:已開未派獎, 3:已開派獎中, 4:取消');
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
        SchemaMonitor::dropIfExists('lucky_draws');
    }
};
