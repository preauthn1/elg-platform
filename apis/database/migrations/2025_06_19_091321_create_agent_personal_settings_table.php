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
        SchemaMonitor::create('agent_personal_settings', function (Blueprint $table) {
            $table->comment('代理帳號個人設置');
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('代理id');
            $table->integer('setting_id')->index()->comment('設置鍵值');
            $table->string('sub_category', 50)->nullable()->comment('子分類(可能是語系)');
            $table->string('value', 200)->comment('設置value');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->comment('建立人');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('操作者');

            $table->unique(['agent_id', 'setting_id', 'sub_category'], 'unique_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_personal_settings');
    }
};
