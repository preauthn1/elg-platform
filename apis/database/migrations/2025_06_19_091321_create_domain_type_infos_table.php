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
        SchemaMonitor::create('domain_type_infos', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('domain_type_id')->default(0)->index()->comment('網域類型系統編號,0為公版');
            $table->integer('template_id')->default(0)->comment('模版系統編號,0為公版');
            $table->boolean('mobile_templates')->default(false)->comment('手機模版系統編號');
            $table->integer('pc_templates')->default(0)->comment('電腦模版系統編號');
            $table->string('logo_pc', 191)->nullable()->comment('電腦版Logo');
            $table->string('logo_mobile', 191)->nullable()->comment('手機版Logo');
            $table->string('icon', 191)->nullable()->comment('favorite icon');
            $table->string('add_home_image', 191)->nullable()->comment('添加主螢幕教學圖片網址');
            $table->string('title', 191)->nullable()->comment('網站名稱');
            $table->tinyInteger('type')->nullable()->default(0)->comment('類型: 0:正常, 1:推廣用(因為可能會用到短網址, 故其他在回給前端可能不適合)');
            $table->tinyInteger('need_login')->default(1)->comment('是否可使用公開頁面');
            $table->string('info', 191)->nullable()->comment('其他資訊');
            $table->text('memo')->comment('描述');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(1)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('domain_type_infos');
    }
};
