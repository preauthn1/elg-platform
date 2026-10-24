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
        SchemaMonitor::create('haolong_bet_records', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('login_id', 191)->index()->comment('投注者帳號');
            $table->string('bet_id', 191)->index()->comment('投注記錄編號');
            $table->dateTime('bet_time')->comment('投注時間');
            $table->dateTime('utc_bet_time')->nullable()->comment('投注的UTC時間');
            $table->integer('draw_type')->comment('開彩莊家{0:全部, 1:Magnum, 2: PMP, 3: Toto, 4: Singapore, 5: Sabah, 6: Sandakan, 7: Sarawak, 8: GD (豪龙)}');
            $table->date('draw_date')->index()->comment('開彩日期');
            $table->integer('bet_number')->comment('投注號碼');
            $table->string('bet_position', 191)->comment('投注位置');
            $table->decimal('bet_amount', 13, 7)->default(0)->comment('投注額');
            $table->decimal('strike', 13, 7)->nullable()->default(0)->comment('中獎金額');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->index()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('haolong_bet_records');
    }
};
