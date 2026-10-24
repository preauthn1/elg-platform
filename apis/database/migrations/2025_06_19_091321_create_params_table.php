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
        SchemaMonitor::create('params', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('type', 191)->comment('類型');
            $table->string('parameter', 191)->comment('參數');
            $table->integer('value')->comment('值');
            $table->text('info')->nullable();
            $table->integer('sort')->comment('排序');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用,1:啟用');
            $table->string('remark', 191)->comment('備註');
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
        SchemaMonitor::dropIfExists('params');
    }
};
