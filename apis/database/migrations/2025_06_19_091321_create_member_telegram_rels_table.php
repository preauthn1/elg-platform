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
        SchemaMonitor::create('member_telegram_rels', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('brand_id')->comment('brand id');
            $table->bigInteger('tg_user_id')->index()->comment('tg user_id');
            $table->unsignedInteger('member_id')->comment('會員系統編號');
            $table->string('tg_password', 200)->comment('password 明文');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_telegram_rels');
    }
};
