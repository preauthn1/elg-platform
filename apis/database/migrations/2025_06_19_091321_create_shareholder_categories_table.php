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
        SchemaMonitor::create('shareholder_categories', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_group_id')->default(0)->index()->comment('代理個性化設置編號');
            $table->string('name', 50)->default('0')->comment('分桶組別名稱');
            $table->string('code', 50)->default('0')->comment('分桶組別代碼');
            $table->tinyInteger('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:disable, 1:enable');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('shareholder_categories');
    }
};
