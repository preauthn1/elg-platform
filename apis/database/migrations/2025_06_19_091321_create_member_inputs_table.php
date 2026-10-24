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
        SchemaMonitor::create('member_inputs', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('agent_id')->comment('代理系統編號');
            $table->integer('type_id')->comment('輸入框類型系統編號');
            $table->string('title', 191)->comment('輸入框標題');
            $table->string('value', 191)->comment('輸入框值(json格式,ex:{"male":1,"female":2})');
            $table->boolean('is_required')->comment('是否必填0:選填，1:必填');
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
        SchemaMonitor::dropIfExists('member_inputs');
    }
};
