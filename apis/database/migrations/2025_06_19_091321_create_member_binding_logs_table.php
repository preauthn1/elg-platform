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
        SchemaMonitor::create('member_binding_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('type')->default(0)->comment('類別; 1:修改手機, 2:修改email, 3:綁定email');
            $table->integer('member_id')->default(0)->index()->comment('members.id');
            $table->string('before_value', 191)->nullable()->comment('更換前的值');
            $table->string('value', 191)->nullable()->comment('更換後的值');
            $table->string('ip', 45)->comment('IP地址');
            $table->integer('validate_type')->default(0)->comment('驗證類別; 1:手機, 2:email');
            $table->integer('status')->default(0)->comment('狀態; 0:未成功, 1:成功, 2:失敗');
            $table->string('remark', 191)->nullable()->comment('備註');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);

            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_binding_logs');
    }
};
