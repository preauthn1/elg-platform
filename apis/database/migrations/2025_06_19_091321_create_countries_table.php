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
        SchemaMonitor::create('countries', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->string('code', 10)->comment('國家代碼');
            $table->string('name', 32)->comment('國家名稱');
            $table->integer('country_code')->default(0)->comment('國碼');
            $table->string('phone_rule', 191)->default('0')->comment('電話號碼規則');
            $table->tinyInteger('status')->default(0)->comment('狀態 0:停用,1:啟用');
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
        SchemaMonitor::dropIfExists('countries');
    }
};
