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
        SchemaMonitor::create('event_invite_point_logs', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('summary_start_time')->useCurrent()->comment('統計時間，用來避免重複新增');
            $table->integer('type')->comment('類型 1:真實姓名, 2:儲值, 3:投注流水, 4:領獎');
            $table->integer('member_id')->index()->comment('會員系統編號');
            $table->decimal('before_bet', 18, 4)->default(0)->comment('新增前流水');
            $table->decimal('after_bet', 18, 4)->default(0)->comment('新增後流水');
            $table->decimal('before_point', 18, 4)->default(0)->comment('新增前分數');
            $table->decimal('after_point', 18, 4)->default(0)->comment('新增後分數');
            $table->decimal('before_receive', 18, 4)->default(0)->comment('領取前已領取金額');
            $table->decimal('after_receive', 18, 4)->default(0)->comment('領取後已領取金額');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');

            $table->unique(['member_id', 'type', 'summary_start_time'], 'unique_member_type_summary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_invite_point_logs');
    }
};
