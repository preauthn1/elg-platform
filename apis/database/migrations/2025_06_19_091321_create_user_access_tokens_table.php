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
        SchemaMonitor::create('user_access_tokens', function (Blueprint $table) {
            $table->increments('id')->comment('系統編號');
            $table->integer('user_id')->comment('帳號系統編號(User)');
            $table->string('token', 80)->comment('Token');
            $table->dateTime('expire_date')->comment('過期日期');
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
        SchemaMonitor::dropIfExists('user_access_tokens');
    }
};
