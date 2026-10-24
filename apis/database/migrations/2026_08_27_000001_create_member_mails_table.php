<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * 站內信主表 工單4152
 *
 * 與既有群發公告（announcement_messages，對象含聊天群組與代理）不同：
 * 本表專為「對會員發信」而設，四種發送類型分成兩種收件人存法——
 *
 *   指定會員／會員群組 → 展開存進 member_mail_recipients。
 *     群組規模有限（單群組數百人），展開後收件人固定，會員轉出群組
 *     仍收得到已寄出的信，未讀數也不會因群組異動而跳動。
 *
 *   全平臺（組織）／代理及子代理 → 不展開，查詢時動態比對。
 *     這兩種涵蓋數十萬會員，展開存放資料量無法收斂；改以組織或代理
 *     hierarchy 比對，並以 members.created_at <= publish_time 排除
 *     發信後才註冊的會員（信寄出時他還不存在，不該收到）。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('member_mails', function (Blueprint $table) {
            $table->comment('站內信主表（對會員發送）');
            $table->increments('id')->comment('PK流水號');
            $table->tinyInteger('send_type')->comment('發送類型 1:指定會員 2:會員群組 3:全平臺(組織) 4:代理及子代理');
            $table->integer('agent_id')->comment('發信者代理ID');
            $table->integer('organization_id')->default(0)->comment('組織ID(send_type=3 時有值)');
            $table->integer('hierarchy_agent_id')->default(0)->comment('指定代理ID(send_type=4 時有值，該代理及其子代理全部會員)');
            // 群組發信於存檔時即展開成 recipients，此欄只記「當初選了哪些群組 id」
            // （如 [3,7]，僅數個整數）供編輯回填與列表顯示；可見性判定一律走 recipients
            $table->json('member_group_ids')->nullable()->comment('選定的會員群組ID清單(send_type=2 時有值，僅供顯示與編輯回填)');
            $table->dateTime('publish_time')->comment('發布時間(到點才對前台顯示，到點後不可再異動)');
            $table->tinyInteger('status')->default(1)->comment('狀態 0:停用 1:啟用');
            // 觸達率的分母。動態判定型（3/4）涵蓋數十萬會員，每次進後台列表現算
            // 成本過高，故於存檔時算一次存下；展開型（1/2）則等同收件人筆數
            $table->integer('recipient_count')->default(0)->comment('應收人數(存檔時計算，供後台統計觸達率)');
            $table->text('memo')->nullable()->comment('備註(僅後台可見)');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');
            // 站內信量體小（單站數千封），代理過濾後其餘條件的比對成本可忽略
            $table->index('agent_id', 'idx_member_mails_agent');
        });
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('member_mails');
    }
};
