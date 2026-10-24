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
        SchemaMonitor::create('event_refresh_ranking_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('event_refresh_ranking_id')->default(0)->comment('event_refresh_rankings.id');
            $table->integer('member_id')->default(0)->comment('members.id');
            $table->decimal('bet_amount', 18, 4)->default(0)->index()->comment('投注金額');
            $table->dateTime('last_bet_time')->nullable()->index()->comment('最後投注時間');
            $table->integer('prize_content_id')->default(0)->index()->comment('獎項ID;prize_contents.id');
            $table->integer('ranking')->default(0)->comment('名次');
            $table->decimal('point', 18, 4)->default(0)->comment('獎勵金額');
            $table->tinyInteger('type')->default(0)->comment('是否塞入獎項');
            $table->tinyInteger('is_calc')->default(0)->comment('是否計算;0:不計算(電話號碼重複),1:計算,2:後台取消');
            $table->tinyInteger('status')->default(0)->comment('提領結果; 0:未領, 1:已領取');
            $table->dateTime('time')->nullable()->comment('領取時間');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->unique(['event_refresh_ranking_id', 'member_id'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_refresh_ranking_records');
    }
};
