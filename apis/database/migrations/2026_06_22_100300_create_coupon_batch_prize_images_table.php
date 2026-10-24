<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('coupon_batch_prize_images') === false) {
            SchemaMonitor::create('coupon_batch_prize_images', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('coupon_batch_prize_id')->index()->comment('獎項ID; 對應 coupon_batch_prizes.id');
                $table->integer('language_id')->comment('語系系統編號');
                $table->text('image_url')->comment('獎項圖片網址');
                $table->timestamp('created_at')->useCurrent()->comment('建立日期');
                $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('coupon_batch_prize_images');
    }
};
