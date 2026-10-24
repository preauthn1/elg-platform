<?php

declare(strict_types=1);

use App\Enums\Vendor\VendorIdEnum;
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
        SchemaMonitor::create('newbie_challenge_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('agent_id')->nullable()->default(null)->index()->comment('適用代理ID，null=全站');
            $table->decimal('activity_amount', 18, 4)->comment('活動金');
            $table->decimal('challenge_flow_multiple', 18, 4)->comment('挑戰流水倍數');
            $table->decimal('bonus_flow_multiple', 18, 4)->comment('彩金流水倍數');
            $table->decimal('min_unlock_amount', 18, 4)->comment('最低解鎖金額');
            $table->decimal('max_unlock_amount', 18, 4)->comment('最高解鎖金額');
            $table->unsignedInteger('bonus_window_hours')->comment('彩金有效期(小時)');
            $table->json('allowed_vendor_ids')->comment('第二階段挑戰流水計算範圍的遊戲商/遊戲ID清單，如FUMA、WUDI');
            $table->json('bonus_flow_vendor_ids')->nullable()->default(null)->comment('第四階段彩金流水計算範圍的遊戲商/遊戲ID清單，獨立於 allowed_vendor_ids，可能不一致；null=不限制遊戲商');
            $table->tinyInteger('ip_block')->nullable()->default(null)->comment('1=同IP(members.last_login_ip)僅一人可參加');
            $table->tinyInteger('phone_block')->nullable()->default(null)->comment('1=同手機號僅一人可參加');
            $table->tinyInteger('account_block')->nullable()->default(null)->comment('1=同真實姓名(first_name)僅一人可參加');
            $table->tinyInteger('restrict_new_member')->default(1)->comment('1=僅活動開始後註冊的玩家可參加，0=活動開始前註冊的玩家也可參加');
            $table->integer('sort')->default(0)->comment('後台活動列表排序，數字越大越前面');
            $table->tinyInteger('status')->default(0)->index()->comment('狀態; 0:停用, 1:啟用');
            $table->timestamp('start_time')->nullable()->default(null)->comment('活動開始時間');
            $table->timestamp('end_time')->nullable()->default(null)->comment('活動結束時間');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });

        DB::table('newbie_challenge_configs')->insert([
            'agent_id' => null,
            'activity_amount' => 88,
            'challenge_flow_multiple' => 5,
            'bonus_flow_multiple' => 12,
            'min_unlock_amount' => 10,
            'max_unlock_amount' => 1888,
            'bonus_window_hours' => 48,
            'allowed_vendor_ids' => json_encode([
                VendorIdEnum::FUMASW_FREE->value,
                VendorIdEnum::WUDISW_FREE->value,
            ]),
            'ip_block' => 1,
            'phone_block' => 1,
            'account_block' => 1,
            'restrict_new_member' => 1,
            'status' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('newbie_challenge_configs');
    }
};
