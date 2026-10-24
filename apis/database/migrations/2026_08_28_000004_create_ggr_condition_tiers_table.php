<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 條件設置階梯列 工單4124
 *
 * 一列＝一個階梯，結算取「合格會員數 ≥ user_count」的最高階 percentage。
 * 四項金額門檻是**單一會員**各自要達的值（非代理線加總）：合格會員＝依主表
 * logic_type（全 OR／全 AND）逐人檢核四項門檻，0＝該項無要求（金額必為非負，
 * 0 門檻任何人都達標，AND/OR 語意下皆等同不參與）。
 * user_count 與 percentage 皆須逐階嚴格遞增（表單與 Request 雙重驗證）。
 * 更新採 transaction 內全量替換（本表 id 不對外引用）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_condition_tiers', function (Blueprint $table) {
            $table->comment('推廣代理GGR條件設置階梯列 工單4124');
            $table->increments('id')->comment('PK流水號(不對外引用,update全量替換)');
            $table->unsignedInteger('condition_id')->comment('ggr_conditions.id');
            $table->smallInteger('tier')->comment('階序(1-based,依表單列序落庫)');
            $table->integer('user_count')->comment('用戶人數(必要條件,合格會員數>=此值才命中,逐階遞增)');
            $table->decimal('deposit_amount', 18, 2)->default(0)->comment('存款門檻(單一會員各自須達,0=無要求)');
            $table->decimal('total_bet_amount', 18, 2)->default(0)->comment('總投注金額門檻(單一會員,0=無要求)');
            $table->decimal('game_revenue_amount', 18, 2)->default(0)->comment('遊戲總收入門檻(單一會員,0=無要求)');
            $table->decimal('valid_bet_amount', 18, 2)->default(0)->comment('有效流水門檻(單一會員,0=無要求)');
            $table->decimal('percentage', 8, 2)->default(0)->comment('應得百分比%(命中此階代理拿的%,公式的配置給代理GGR%,逐階遞增)');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unique(['condition_id', 'tier'], 'uk_ggr_condition_tier');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_condition_tiers');
    }
};
