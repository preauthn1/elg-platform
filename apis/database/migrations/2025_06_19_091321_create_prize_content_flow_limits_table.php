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
        SchemaMonitor::create('prize_content_flow_limits', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('prize_content_id')->index()->comment('prize_contents.id');
            $table->integer('vendor_id')->comment('vendors.id');
            $table->integer('game_type_id')->comment('game_types.id');
            $table->dateTime('created_at')->useCurrent()->comment('建立日期');
            $table->dateTime('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('prize_content_flow_limits');
    }
};
