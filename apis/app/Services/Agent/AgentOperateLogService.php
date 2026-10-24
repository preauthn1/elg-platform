<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Data\AgentOperateLog\AgentOperateDebugInfo;
use App\Data\AgentOperateLog\ExportDebugInfo;
use App\Data\AgentOperateLog\PlainDebugInfo;
use App\Enums\Agent\AgentOperateReferenceTypeEnum;
use App\Enums\Agent\AgentOperateTypeEnum;
use App\Models\AgentMenu;
use App\Models\AgentOperateLog;
use LogicException;

/**
 * 代理後台操作稽核紀錄。
 *
 * 🔴 APIs 寫入 agent_operate_logs 一律經過 log()，禁止直接 AgentOperateLog::create()：
 * 備註格式由備註物件決定、並與類型宣告的顯示種類比對，繞過它就會長出讀取端認不得的寫法。
 *
 * debug_info 會原樣回傳給所有能查詢操作紀錄的上層代理，因此條件過濾一律在後端重跑一次，
 * 不信任 WEB_AgentBackend 送來的內容；陣列型的備註寫入前一律經 stripCredentials() 剔除登入憑證。
 */
class AgentOperateLogService
{
    /** 單一條件值長度上限（字元） */
    private const int CONDITION_VALUE_MAX_LENGTH = 200;

    /** 條件鍵名長度上限（字元） */
    private const int CONDITION_KEY_MAX_LENGTH = 50;

    /** 條件筆數上限 */
    private const int CONDITION_MAX_KEYS = 50;

    /** agent_menus.status = 1 才是啟用中、實際渲染在左側選單上的那筆 */
    private const int MENU_STATUS_ENABLED = 1;

    /**
     * 匯不出選單時的 reference_id。
     *
     * 讀取端以 reference_id 對 agents.id／members.id 做 leftJoin，
     * 寫入任何可能撞到既有 id 的值都會誤帶出無關的使用者名稱，因此固定寫 '0'
     * 並讓 reference_type 維持資料表預設的空字串（UNKNOWN）。
     */
    private const string REFERENCE_ID_NONE = '0';

    /** 分頁／控制／傳輸用欄位，小寫化後完全比對剔除 */
    private const array CONTROL_KEYS = [
        'action', 'menu_name', 'menu_url', 'record_count',
        'page', 'per_page', 'offset', 'limit',
        'params', 'key', '_token', 'csrf_token', '_',
    ];

    /**
     * 敏感欄位（完全比對）。
     *
     * 這些是短字串，只能完全比對 —— 放進子字串清單會誤殺 sign_up_time、assign_agent、design_id 等正常欄位。
     */
    private const array SENSITIVE_EXACT_KEYS = [
        'sign', 'signature', 'token', 'key', 'otp', 'cvv',
        'session', 'cookie', 'authorization', 'auth',
    ];

    /** 敏感欄位（子字串比對，這些字串不會出現在正常查詢條件中） */
    private const array SENSITIVE_NEEDLES = [
        'password', 'passwd', 'pwd',
        'secret', 'private_key', 'api_key', 'apikey',
        'access_token', 'refresh_token',
        'validate_code', 'verify_code', 'sms_code', 'captcha',
        'card_no', 'cardno', 'bank_account', 'account_no',
        'id_card', 'id_number', 'identity_no',
        'google2fa', 'two_factor',
    ];

    /**
     * API_Agent Authenticate 併進 request 的身分欄位（區分大小寫），其中 TOKEN 是操作者有效的登入憑證。
     * 須與 API_Agent 的 App\Helpers\OperateLogSanitizer 一致。
     */
    private const array AUTH_CONTEXT_KEYS = [
        'ID', 'HOST', 'PARENT', 'ROLE_ID', 'HIERARCHY_PATH', 'USERNAME', 'CODE', 'TOKEN', 'LANGUAGE',
        'ORGANIZATION_ID', 'CUSTOMIZED_SETTING_GROUP', 'CASH_FLOW_GROUP', 'SMS_GATEWAY_GROUP',
        'CHAT_GROUP', 'VENDOR_CUSTOMIZE_GROUP', 'MEMBER_FIELD_SETTING_GROUP',
    ];

    /**
     * 登入憑證欄位（小寫化後完全比對）。與 SENSITIVE_* 分開：那組連銀行帳號等 PII 都剔除，只適合匯出條件。
     * 須與 API_Agent 的 App\Helpers\OperateLogSanitizer 一致。
     */
    private const array CREDENTIAL_EXACT_KEYS = ['token', 'otp', 'secret', 'im_certify'];

    /** 登入憑證類欄位（子字串比對，小寫化後）；`_token` 涵蓋 agent_token、bot_token、remember_token，不會誤中 token_expired */
    private const array CREDENTIAL_NEEDLES = [
        'password', 'passwd', 'pwd', 'secret', 'private_key', 'api_key', 'apikey', '_token',
        'google2fa', 'two_factor', 'validate_code', 'verify_code', 'sms_code',
    ];

    /**
     * 寫入一筆操作紀錄（APIs 唯一的寫入入口）。
     *
     * 備註物件的顯示種類必須與類型宣告的一致，不一致就拋例外、不寫入。
     * 呼叫端在交易內呼叫時，紀錄會隨交易一起提交或回滾。
     *
     * @param  string  $ip  稽核用來源 IP；代理後台操作用 Common::getAgentClientIp()（理由見該方法）
     *
     * @throws LogicException 備註物件與類型宣告的顯示種類不一致
     */
    public function log(
        int $agentId,
        AgentOperateTypeEnum $type,
        AgentOperateDebugInfo $debugInfo,
        string $ip,
        string $referenceId = self::REFERENCE_ID_NONE,
        AgentOperateReferenceTypeEnum $referenceType = AgentOperateReferenceTypeEnum::UNKNOWN,
    ): AgentOperateLog {
        if ($debugInfo->kind() !== $type->debugInfoKind()) {
            throw new LogicException(sprintf(
                'Operate log type %s expects %s debug info, got %s',
                $type->value,
                $type->debugInfoKind()->value,
                $debugInfo->kind()->value,
            ));
        }

        if ($debugInfo instanceof PlainDebugInfo && is_array($debugInfo->content)) {
            $debugInfo = new PlainDebugInfo($this->stripCredentials($debugInfo->content));
        }

        return AgentOperateLog::create([
            'agent_id' => $agentId,
            'type' => $type->value,
            'reference_id' => $referenceId,
            'reference_type' => $referenceType->value,
            'debug_info' => $debugInfo->toDebugInfo(),
            'ip' => $ip,
        ]);
    }

    /**
     * 寫入一筆 Excel 匯出稽核紀錄。
     *
     * @param  string  $menuUrl  匯出當下的頁面路徑（member/index），用來反查 agent_menus.id
     * @param  array<array-key, mixed>  $conditions
     */
    public function logExport(
        int $agentId,
        string $ip,
        string $action,
        string $menuName,
        string $menuUrl,
        array $conditions,
        int $recordCount,
    ): AgentOperateLog {
        $menuId = $this->resolveMenuId($menuUrl);

        return $this->log(
            agentId: $agentId,
            type: AgentOperateTypeEnum::EXPORT_DATA,
            debugInfo: $this->makeExportDebugInfo($action, $menuName, $conditions, $recordCount),
            ip: $ip,
            referenceId: $menuId === null ? self::REFERENCE_ID_NONE : (string) $menuId,
            referenceType: $menuId === null
                ? AgentOperateReferenceTypeEnum::UNKNOWN
                : AgentOperateReferenceTypeEnum::AGENT_MENU,
        );
    }

    /**
     * 遞迴剔除登入憑證與 API_Agent 併進來的身分欄位；清理既有紀錄的指令也用它，新舊紀錄才會一致。
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function stripCredentials(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isCredentialKey($key)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->stripCredentials($value);
            }
        }

        return $data;
    }

    private function isCredentialKey(string $key): bool
    {
        if (in_array($key, self::AUTH_CONTEXT_KEYS, true)) {
            return true;
        }

        $lowerKey = strtolower($key);

        if (in_array($lowerKey, self::CREDENTIAL_EXACT_KEYS, true)) {
            return true;
        }

        foreach (self::CREDENTIAL_NEEDLES as $needle) {
            if (str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 以頁面路徑反查 agent_menus.id。
     *
     * id 一律由後端查出，不接受 WEB_AgentBackend 直接送 —— 稽核紀錄的指向不能由被稽核者決定。
     *
     * 只認 status=1（啟用中的選單），詳見下方註解。
     *
     * 查不到就回 null（reference_id 維持 '0'）而不是報錯：
     * 路徑來源是 HTTP_REFERER 與 session，瀏覽器不送 referer、匯出頁不在選單裡（例如各種
     * 明細子頁）、或該選單已停用都會落空，那是預期情況，不該讓整個匯出 fail-closed 被擋下來。
     * 此時選單名稱仍留在 debug_info.menu_name，讀取端會退回顯示它。
     */
    private function resolveMenuId(string $menuUrl): ?int
    {
        $menuUrl = trim($menuUrl);

        if ($menuUrl === '') {
            return null;
        }

        // 只認啟用中的選單：左側選單只渲染 status=1（API_Agent AgentMenuController 組選單時同樣濾
        // status=1），使用者實際點進去的就是它。停用的選單不該成為稽核紀錄的指向對象。
        //
        // url 沒有 unique 約束（report/agent_transaction_logs、shareholder/performance_query
        // 目前各有兩筆），同為啟用時取最小 id，讓同一頁面永遠對到同一筆。
        $menuId = AgentMenu::where('url', $menuUrl)
            ->where('status', self::MENU_STATUS_ENABLED)
            ->orderBy('id')
            ->value('id');

        return $menuId === null ? null : (int) $menuId;
    }

    /**
     * 組出匯出紀錄的 debug_info JSON；格式與長度上限見 ExportDebugInfo。
     *
     * @param  array<array-key, mixed>  $conditions
     */
    public function buildDebugInfo(string $action, string $menuName, array $conditions, int $recordCount): string
    {
        return $this->makeExportDebugInfo($action, $menuName, $conditions, $recordCount)->toDebugInfo();
    }

    /**
     * @param  array<array-key, mixed>  $conditions
     */
    private function makeExportDebugInfo(string $action, string $menuName, array $conditions, int $recordCount): ExportDebugInfo
    {
        return new ExportDebugInfo(
            action: $action,
            menuName: $menuName,
            conditions: $this->sanitizeConditions($conditions),
            recordCount: $recordCount,
        );
    }

    /**
     * 剔除分頁／控制／敏感欄位與空值，並限制鍵數與值長度。
     *
     * @param  array<array-key, mixed>  $conditions
     * @return array<string, string>
     */
    public function sanitizeConditions(array $conditions): array
    {
        $result = [];

        foreach ($conditions as $key => $value) {
            if (count($result) >= self::CONDITION_MAX_KEYS) {
                break;
            }

            $key = (string) $key;
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, self::CONTROL_KEYS, true)
                || in_array($lowerKey, self::SENSITIVE_EXACT_KEYS, true)) {
                continue;
            }

            foreach (self::SENSITIVE_NEEDLES as $needle) {
                if (str_contains($lowerKey, $needle)) {
                    continue 2;
                }
            }

            $value = match (true) {
                is_array($value) => implode(',', array_map(
                    static fn ($item): string => is_scalar($item) ? (string) $item : '',
                    $value,
                )),
                is_bool($value) => $value ? '1' : '0',
                is_scalar($value) => (string) $value,
                default => '',
            };

            // WEB 端表單 serialize 會帶出大量空欄位，不具稽核價值
            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $result[mb_substr($key, 0, self::CONDITION_KEY_MAX_LENGTH)]
                = mb_substr($value, 0, self::CONDITION_VALUE_MAX_LENGTH);
        }

        return $result;
    }
}
