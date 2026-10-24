<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (SchemaMonitor::hasTable('reward_claim_records') === false) {
            SchemaMonitor::create('reward_claim_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('member_id')->index()->comment('會員系統編號');
                $table->unsignedInteger('event_detail_id')->default(0)->index()->comment('event_details.id');
                $table->tinyInteger('type')->comment('1:真實姓名 2:註冊IP 3:手機號碼 4:領獎IP 5:Telegram ID');
                $table->string('value')->comment('儲存值');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('reward_claim_records');
    }
};
