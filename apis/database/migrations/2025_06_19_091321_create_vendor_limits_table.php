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
        SchemaMonitor::create('vendor_limits', function (Blueprint $table) {
            $table->comment('遊戲商限紅');
            $table->increments('id')->comment('系統編號');
            $table->integer('vendor_id')->comment('遊戲商系統編號(Vendors)');
            $table->integer('curency_id')->default(0)->comment('幣別系統編號(currencies)');
            $table->integer('game_type_id')->default(0)->comment('遊戲類別系統編號(game_types)');
            $table->string('name', 80)->comment('名稱');
            $table->decimal('min_amount', 18, 4)->comment('最小下注');
            $table->decimal('max_amount', 18, 4)->comment('最大下注');
            $table->text('remark')->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vendor_limits');
    }
};
