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
        SchemaMonitor::create('agent_authentications', function (Blueprint $table) {
            $table->comment('代理; ID:3~100預設給系統使用, 如System, Auto, unlimit, ....');
            $table->increments('id')->comment('系統編號');
            $table->integer('agent_id')->default(0)->index()->comment('主帳號的系統編號(不等於0就是子帳號)');
            $table->boolean('type')->default(true)->index()->comment('類型; 1:Google2FA');
            $table->string('secret', 50)->nullable()->comment('secret key');
            $table->text('image')->nullable()->comment('img');
            $table->boolean('status')->default(true)->index()->comment('帳號狀態 0:停用,1:啟用');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
            $table->integer('admin')->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_authentications');
    }
};
