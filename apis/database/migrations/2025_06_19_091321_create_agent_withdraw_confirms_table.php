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
        SchemaMonitor::create('agent_withdraw_confirms', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->integer('agent_id')->default(0)->index()->comment('agent_id');
            $table->string('username', 50)->nullable();
            $table->tinyInteger('withdraw_confirm')->nullable()->default(1)->comment('洗分是否需要同意，0:不需要、1:需要');
            $table->boolean('status')->default(false)->index()->comment('狀態; 0:禁用,1:使用中');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(51)->comment('更新者ID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_withdraw_confirms');
    }
};
