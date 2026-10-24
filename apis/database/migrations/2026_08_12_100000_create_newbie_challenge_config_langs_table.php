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
        SchemaMonitor::create('newbie_challenge_config_langs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('config_id')->index()->comment('newbie_challenge_configs.id');
            $table->unsignedInteger('language_id')->index()->comment('languages.id');
            $table->string('name')->comment('活動名稱');
            $table->string('image')->nullable()->default(null)->comment('活動圖片');
            $table->text('content')->comment('活動說明與規則文字');

            $table->unique(['config_id', 'language_id']);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'))->index();
        });

        // 為既有的預設活動設定補上英文(id=1)／繁中(id=2)內容，其餘語系 fallback 英文
        $configIds = DB::table('newbie_challenge_configs')->pluck('id');

        foreach ($configIds as $configId) {
            DB::table('newbie_challenge_config_langs')->insert([
                [
                    'config_id' => $configId,
                    'language_id' => 1,
                    'name' => 'Newbie Exclusive Challenge',
                    'content' => "Complete the challenge to unlock up to 1888 bonus\n"
                        . "1. Claim 88 activity funds to start the challenge\n"
                        . "2. Complete 440 valid turnover on designated games\n"
                        . "3. Deposit to unlock bonus (unlock as much as you deposit, up to 1888)\n\n"
                        . "Rules:\n"
                        . "1. Each new member may only participate once\n"
                        . "2. KYC verification is required before claiming\n"
                        . "3. Withdrawals and transfers are disabled during the activity\n"
                        . '4. Designated games: FUMA, WUDI',
                ],
                [
                    'config_id' => $configId,
                    'language_id' => 2,
                    'name' => '新人專享挑戰',
                    'content' => "完成挑戰，最高解鎖1888彩金\n"
                        . "1.領取88活動金，開啟新人挑戰\n"
                        . "2.在指定遊戲完成440有效流水\n"
                        . "3.充值解鎖彩金(充多少得多少，最高1888)\n\n"
                        . "規則說明：\n"
                        . "1.每位新會員僅可參與一次\n"
                        . "2.需完成實名認證(KYC)後方可領取\n"
                        . "3.活動期間禁止提款、轉帳\n"
                        . '4.指定遊戲：FUMA、WUDI',
                ],
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('newbie_challenge_config_langs');
    }
};
