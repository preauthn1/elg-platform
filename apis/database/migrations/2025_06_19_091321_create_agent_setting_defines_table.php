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
        SchemaMonitor::create('agent_setting_defines', function (Blueprint $table) {
            $table->comment('代理個人設置鍵值定義');
            $table->integer('setting_id')->primary()->comment('PK，非自動流水號');
            $table->integer('parent')->index()->comment('若是type2，會記錄上層分類id');
            $table->integer('type')->index()->comment('type: 1 鍵值分類，type 2: 鍵值 (分類相同的設置是整組一起編輯的)');
            $table->integer('is_visible')->default(1)->index()->comment('是否後台可看到編輯');
            $table->tinyInteger('agent_edit')->nullable()->default(0)->comment('允許代理編輯');
            $table->string('memo', 191)->nullable()->comment('備註說明');
            $table->string('language_text_code', 191)->index()->comment('多語系對應代碼');
            $table->string('default_value', 200)->nullable()->comment('首次編輯要帶入的預設值');
            $table->string('data_type', 50)->nullable()->index()->comment('資料類型');
            $table->integer('is_data_required')->default(0)->comment('此類型設置是否為必填');
            $table->text('enable_agents')->nullable()->comment('是否僅有特定代理可編輯此項目');
            $table->tinyInteger('sort')->default(0)->comment('排序');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->integer('created_by')->comment('建立人');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_setting_defines');
    }
};
