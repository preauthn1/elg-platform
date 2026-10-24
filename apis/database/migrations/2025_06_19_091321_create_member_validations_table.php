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
        SchemaMonitor::create('member_validations', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('member_id')->comment('帳號系統編號(members)');
            $table->tinyInteger('type_id')->comment('驗證類型系統編號');
            $table->string('image', 191)->comment('檔案位置');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->tinyInteger('status')->default(0)->comment('是否通過審核 0: 未通過 1:通過 2:拒絕');
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
        SchemaMonitor::dropIfExists('member_validations');
    }
};
