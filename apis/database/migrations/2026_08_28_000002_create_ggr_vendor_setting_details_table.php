<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 推廣代理 GGR 遊戲商設置明細 工單4123
 *
 * 每列＝vendor_info × game_type 的**一個階層**：同組合可多階，
 * 以 threshold_amount（計算金額>=）為階梯鍵、逐階嚴格遞增，
 * 結算取「公司輸贏達標的最高階」的 min/max/加權設定。
 * excluded_game_ids 與 status 為同組合各階共用，僅存於 tier=1 列。
 *
 * min_winlose / max_ggr 用 NULL 表示「無最低限制／無上限」，不用 0 哨兵：
 * max_ggr=0 與「無上限」語意相反（會把業績封頂歸零），NULL 在結算可直接 skip。
 * 更新採 transaction 內全量替換（本表 id 不對外引用）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('ggr_vendor_setting_details', function (Blueprint $table) {
            $table->comment('推廣代理GGR遊戲商設置明細(vendor_info×game_type×階層) 工單4123');
            $table->increments('id')->comment('PK流水號(不對外引用,update全量替換)');
            $table->unsignedInteger('setting_id')->comment('ggr_vendor_settings.id');
            $table->integer('vendor_id')->comment('vendors.id(結算彙總鍵,summary表以此維度)');
            $table->integer('vendor_info_id')->comment('vendor_infos.id(彈窗列顯示主體,限總代支援範圍)');
            $table->integer('game_type_id')->comment('game_types.id(彈窗tab維度)');
            $table->smallInteger('tier')->default(1)->comment('階序(1-based,同組合內threshold_amount逐階遞增)');
            $table->json('excluded_game_ids')->nullable()->comment('排除遊戲id陣列(同組合各階共用,存tier=1列)');
            $table->tinyInteger('status')->default(1)->comment('該組合開關 0:不列入結算 1:啟用(各階共用,存tier=1列)');
            $table->decimal('threshold_amount', 18, 2)->comment('計算金額>=(階梯鍵,公司輸贏達此值才適用此階,正數)');
            $table->unsignedBigInteger('min_winlose')->nullable()->comment('最低遊戲輸贏(正整數,列入計算下限,NULL=無最低限制)');
            $table->unsignedBigInteger('max_ggr')->nullable()->comment('最大GGR(正整數,可計算加權上限,NULL=無上限)');
            $table->decimal('weight_rate', 8, 2)->comment('加權%(遊戲輸贏×加權=代理業績,正數)');
            $table->integer('admin')->default(0)->comment('更新者ID');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->unique(['setting_id', 'vendor_info_id', 'game_type_id', 'tier'], 'uk_ggr_vendor_setting_detail_tier');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('ggr_vendor_setting_details');
    }
};
