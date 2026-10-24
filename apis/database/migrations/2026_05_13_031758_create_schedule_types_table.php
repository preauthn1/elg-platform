<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'schedule_types';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->comment('玩家類別異動紀錄');
            $table->increments('id')->comment('系統編號');
            $table->string('name', 500)->nullable()->comment('排程名稱');
            $table->text('memo')->nullable()->comment('技術記錄用');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(51)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};
