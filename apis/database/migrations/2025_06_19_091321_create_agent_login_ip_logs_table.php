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
        SchemaMonitor::create('agent_login_ip_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('agent_id')->index()->comment('代理編號');
            $table->bigInteger('ip')->nullable()->index()->comment('登入ip');
            $table->integer('ip_1')->index()->comment('ip_1');
            $table->integer('ip_2')->index()->comment('ip_2');
            $table->integer('ip_3')->index()->comment('ip_3');
            $table->integer('ip_4')->index()->comment('ip_4');
            $table->timestamp('created_at')->useCurrent()->index()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_login_ip_logs');
    }
};
