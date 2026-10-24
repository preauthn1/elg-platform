<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * members.event_mode 存的是 prize_contents.id（鎖分模式綁定的獎項），不是布林值。
 *
 * - SP_event_deposit.sql: UPDATE members SET event_mode = PrizeContentId
 * - SP_calc_flow_limit.sql: 達標後 UPDATE members SET event_mode = 0
 * - BetHelper::…: where('prize_content_id', $member->event_mode)
 * - CouponController / PrizeService: $member->update(['event_mode' => $prizeContentId])
 *
 * 正式環境本來就是 int，但建表 migration 誤寫成 boolean(tinyint)，
 * 由 migration 建出來的環境（含測試 DB）在 prize_contents.id > 127 時會寫入失敗。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->integer('event_mode')->default(0)->comment('鎖分模式綁定的 prize_contents.id；0 為未鎖定')->change();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->boolean('event_mode')->default(false)->comment('是否進入活動鎖定模式0:未鎖定，1:鎖定')->change();
        });
    }
};
