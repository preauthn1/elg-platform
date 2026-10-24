<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * agent_operate_logs.type
 *
 * 此表由 API_Agent 與 apis 共寫；新增 case 必須同步 API_Agent 的
 * app/Enums/AgentSystemConstant.php::availableOperateTypes()，否則代理後台查不到該筆紀錄。
 *
 * 新增 case 時必須在 debugInfoKind() 宣告備註的顯示種類；漏寫會在寫入時拋 UnhandledMatchError。
 */
enum AgentOperateTypeEnum: string
{
    /** 後台修改會員遊戲帳號密碼 */
    case RESET_VENDOR_PASSWORD = [REDACTED]'; // hardcode-ok: 操作類型代碼，不是密碼

    /** 後台資料匯出（Excel） */
    case EXPORT_DATA = 'export_data';

    /** 編輯代理（API_Agent availableOperateTypes 既有 type，共用） */
    case UPDATE_AGENT = 'update_agent';
    /** 編輯會員：後台切換會員狀態（API_Agent availableOperateTypes 既有 type，共用；target/tickets#4438） */
    case UPDATE_MEMBER = 'update_member';

    /** 提款單人工標記代付失敗（21／22 → 29） */
    case WITHDRAWAL_MARK_FAILED = 'withdrawal_mark_failed';

    /** 提款單人工填寫三方單號（21 → 22） */
    case WITHDRAWAL_FILL_THIRD_PARTY_NO = 'withdrawal_fill_third_party_no';

    /** 儲存電子郵件組別設定 */
    case EMAIL_GROUP_DETAIL_UPDATE = 'email_group_detail_update';

    /** 儲存電子郵件全部樣板 */
    case EMAIL_TEXT_UPDATE = 'email_text_update';

    /** 查看電子郵件發送紀錄的內容 */
    case VIEW_EMAIL_CONTENT = 'view_email_content';

    /** 本人開啟 Google 2FA（target/tickets#4521；#4518 自 API_Agent 搬入 apis） */
    case ENABLE_AGENT_2FA = 'enable_agent_2fa';

    /** 關閉 Google 2FA（自己或他人，target/tickets#4503） */
    case DISABLE_AGENT_2FA = 'disable_agent_2fa';

    /** 重置他人 Google 2FA（關閉並作廢舊金鑰，target/tickets#4503） */
    case RESET_AGENT_2FA = 'reset_agent_2fa';

    /** 備註（debug_info）的顯示種類 */
    public function debugInfoKind(): AgentOperateDebugInfoKindEnum
    {
        return match ($this) {
            self::WITHDRAWAL_MARK_FAILED,
            self::WITHDRAWAL_FILL_THIRD_PARTY_NO => AgentOperateDebugInfoKindEnum::STATUS_CHANGE,
            self::EXPORT_DATA => AgentOperateDebugInfoKindEnum::EXPORT,
            self::RESET_VENDOR_PASSWORD,
            self::UPDATE_AGENT,
            self::UPDATE_MEMBER,
            self::EMAIL_GROUP_DETAIL_UPDATE,
            self::EMAIL_TEXT_UPDATE,
            self::VIEW_EMAIL_CONTENT,
            self::ENABLE_AGENT_2FA,
            self::DISABLE_AGENT_2FA,
            self::RESET_AGENT_2FA => AgentOperateDebugInfoKindEnum::PLAIN,
        };
    }
}
