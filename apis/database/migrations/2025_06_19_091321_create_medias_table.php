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
        SchemaMonitor::create('medias', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 32)->comment('媒體代碼');
            $table->string('name', 50)->comment('媒體名稱');
            $table->string('info', 191)->comment('媒體資訊');
            $table->tinyInteger('status')->comment('狀態');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('medias');
    }
};
