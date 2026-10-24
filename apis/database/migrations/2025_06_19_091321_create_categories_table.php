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
        SchemaMonitor::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('organization_id')->index()->comment('organizations.id');
            $table->string('name', 50)->comment('name');
            $table->string('code', 50)->index()->comment('代理ID(agents.id)');
            $table->tinyInteger('sort')->default(0)->comment('排序');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:停用, 1:啟用');
            $table->string('remark', 200)->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作人員');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('categories');
    }
};
