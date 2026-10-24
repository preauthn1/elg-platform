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
        SchemaMonitor::create('member_access_tokens', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('member_id')->index()->comment('會員帳號系統編號(Member)');
            $table->string('token', 80)->index()->comment('Token');
            $table->dateTime('expire_date')->index()->comment('過期日期');
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
        SchemaMonitor::dropIfExists('member_access_tokens');
    }
};
