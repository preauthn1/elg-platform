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
        SchemaMonitor::create('cancel_unlimit_logs', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->date('date')->comment('日期');
            $table->integer('member_id')->default(0)->index()->comment('會員系統編號');
            $table->decimal('bet', 18, 4)->default(0)->comment('投注額');
            $table->tinyInteger('type')->comment('1: 禁止派獎, 2: 可以派獎');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('cancel_unlimit_logs');
    }
};
