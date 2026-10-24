<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('event_v2_reject_joins', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_v2_detail_id')->index()->comment('event_v2_details.id');
            $table->unsignedInteger('member_id')->index()->comment('members.id');
            $table->unsignedInteger('last_event_v2_reject_record_id')->nullable()->default(null)->index()->comment('最新一筆拒絕紀錄id');
            $table->unsignedInteger('event_v2_user_join_id')->nullable()->default(null)->index()->comment('event_v2_userjoins.id');
            $table->timestamp('allowed_time')->nullable()->default(null)->comment('允許參加時間')->index();
            $table->tinyInteger('status')->default(1)->comment('狀態');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });

        SchemaMonitor::create('event_v2_reject_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('event_v2_reject_join_id')->index()->comment('event_v2_reject_joins.id');
            $table->string('validate_code')->index()->comment('驗證code，請參照 EventV2ValidateCodeEnum');
            $table->string('message')->comment('繁體中文錯誤訊息');
            $table->string('ip')->index()->comment('玩家當下IP');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_v2_reject_joins');
        SchemaMonitor::dropIfExists('event_v2_reject_records');
    }
};
