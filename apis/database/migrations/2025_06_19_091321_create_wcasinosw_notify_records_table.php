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
        SchemaMonitor::create('wcasinosw_notify_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('bet_id', 200)->comment('交易 id, serialnumber from request');
            $table->string('notify_id', 200)->unique()->comment('通知 id, notifyid from request');
            $table->tinyInteger('type')->comment('請求類型, type from request');
            $table->boolean('is_done')->default(false)->comment('是否完成, 0: 否, 1: 是');
            $table->boolean('is_rollback')->default(false)->comment('是否為撤單, 0: 否, 1: 是');
            $table->decimal('amount', 18, 4)->default(0)->comment('金額');
            $table->json('infos')->nullable()->comment('詳細資訊');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('wcasinosw_notify_records');
    }
};
