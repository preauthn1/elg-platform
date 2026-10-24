<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 後台操作代號（操作紀錄統一檢視）。
 *
 * artisan 型：只有列在此處的指令能經由後台「系統操作」頁面觸發；
 * value 即 artisan 指令名，參數由各操作的 form schema 驗證後帶入。
 * 稽核型：非 artisan 指令、不經系統操作頁派發，僅作為
 * admin_operation_logs 的操作代號供統一檢視（如後台帳號管理動作）。
 */
enum AdminOperationEnum: string
{
    /** 更新遊戲商遊戲清單 */
    case UpdateGameList = 'update_game_list';

    /** 拉取遊戲商注單 */
    case FetchBetRecord = 'fetch_bet_record';

    /** 重跑注單彙總（SBR） */
    case SummaryBetRecord = 'summary_bet_record';

    /** 資料表加索引（建鏡像表）／加欄位（INSTANT） */
    case SchemaOperation = 'schema_operation';

    /** 補建遊戲帳號池 */
    case CreateVendorAccount = 'create_vendor_account';

    /** 轉回遊戲商內所有玩家餘額至平台錢包（換商戶／掛維護前抽分） */
    case TransferMoneyBack = 'vendor:transfer_money_back';

    /** GGR 推廣代理結算（手動補跑／重算，target/tickets#4121） */
    case GgrSettlement = 'ggr:settle';

    /** GGR 推廣代理發放（手動觸發未發放列入帳，target/tickets#4121 批次七） */
    case GgrPayout = 'ggr:payout';

    /** 掃描 Redis 記憶體佔用（前綴分布＋最大的 key） */
    case RedisMemoryAnalyze = 'redis:analyze-memory';

    /** 新增後台帳號（稽核型，UserResource CreateUser 寫入） */
    case CreateUser = 'create_user';

    /** 修改後台帳號密碼（稽核型：帳號管理修改他人密碼／Profile 自助修改） */
    case ChangePassword = [REDACTED]';

    /** 變更後台帳號角色（稽核型：帳號管理，僅 superadmin） */
    case ChangeRole = 'change_role';

    /** 後台登入（稽核型：SSO 或超級管理員密碼登入，params.via 區分） */
    case Login = 'login';

    /** 修改遊戲商資訊（稽核型：遊戲商管理編輯頁，params.changes 記有變動欄位的改前改後） */
    case UpdateVendor = 'update_vendor';

    /** 停用遊戲商（稽核型：遊戲商管理，以停用取代刪除） */
    case DisableVendor = 'disable_vendor';

    /** 啟用遊戲商（稽核型：遊戲商管理） */
    case EnableVendor = 'enable_vendor';

    /** Olympus 金流商管理：修改回調來源 IP 白名單（其餘欄位唯讀） */
    case UpdateCashFlowWhitelist = 'update_cash_flow_whitelist';

    public function label(): string
    {
        return match ($this) {
            self::UpdateGameList => '更新遊戲清單',
            self::FetchBetRecord => '拉取注單',
            self::SummaryBetRecord => '重跑注單彙總',
            self::SchemaOperation => '資料表加索引／加欄位',
            self::CreateVendorAccount => '補建遊戲帳號',
            self::TransferMoneyBack => '轉回遊戲商玩家餘額',
            self::GgrSettlement => 'GGR 推廣代理結算',
            self::GgrPayout => 'GGR 推廣代理發放',
            self::RedisMemoryAnalyze => 'Redis 記憶體分析',
            self::CreateUser => '新增後台帳號',
            self::ChangePassword => '修改密碼',
            self::ChangeRole => '變更角色',
            self::Login => '登入',
            self::UpdateVendor => '修改遊戲商',
            self::DisableVendor => '停用遊戲商',
            self::EnableVendor => '啟用遊戲商',
            self::UpdateCashFlowWhitelist => '修改金流商白名單',
        };
    }

    /**
     * 同一操作重複執行的封鎖時效（秒）：
     * running 紀錄超過此時間視為殭屍（worker 被砍等異常），放行新的執行。
     */
    public function staleSeconds(): int
    {
        return match ($this) {
            self::UpdateGameList,
            self::FetchBetRecord,
            self::SummaryBetRecord,
            self::SchemaOperation,
            self::CreateVendorAccount,
            self::TransferMoneyBack,
            self::GgrSettlement,
            self::GgrPayout,
            self::RedisMemoryAnalyze => 3600,
            // 稽核型紀錄即寫即完成，無執行期，不參與重複執行封鎖判斷
            self::CreateUser, self::ChangePassword, self::ChangeRole, self::Login,
            self::UpdateVendor, self::DisableVendor, self::EnableVendor => 0,
        };
    }
}
