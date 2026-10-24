<?php

declare(strict_types=1);

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\EmailTextTypeEnum;
use App\Services\EmailTextService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 為既有的電子郵件設置組別補 8 個類型的全語系預設樣板 工單4286
 *
 * 寄信改為「查無樣板即中斷」，既有組別若沒有這批預設列，上版當下會立刻寄不出驗證信、
 * 註冊與忘記密碼直接中斷。預設文字＝改動前寫死在程式裡的那一句，行為與改動前一致。
 * 之後才建立的組別由 EmailTextService::ensureDefaults() 於首次載入／寄信時補齊。
 */
return new class extends Migration
{
    public function up(): void
    {
        $groupIds = DB::table('agent_groups')
            ->where('agent_group_type', AgentGroupTypeEnum::EMAIL_GATEWAY->value)
            ->pluck('id');

        $now = now();
        $rows = [];
        foreach ($groupIds as $groupId) {
            foreach (EmailTextTypeEnum::cases() as $type) {
                $rows[] = [
                    'email_gateway_group_id' => (int) $groupId,
                    'type_id' => $type->value,
                    'language_id' => EmailTextService::ALL_LANGUAGE_ID,
                    'subject' => EmailTextService::DEFAULT_SUBJECT,
                    'content' => EmailTextService::DEFAULT_CONTENT,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'admin' => 0,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('email_texts')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        // 只收回本 migration 種下、且未被運營改過的預設列
        DB::table('email_texts')
            ->where('language_id', EmailTextService::ALL_LANGUAGE_ID)
            ->where('subject', EmailTextService::DEFAULT_SUBJECT)
            ->where('content', EmailTextService::DEFAULT_CONTENT)
            ->delete();
    }
};
