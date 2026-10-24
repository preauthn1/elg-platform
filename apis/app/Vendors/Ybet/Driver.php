<?php

declare(strict_types=1);

namespace App\Vendors\Ybet;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Data\VendorTransferData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\HttpCode;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\YbetAccount;
use App\Vendors\Models\YbetBetRecord;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 億博 Yeebet 52
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    /**
     * Yeebet 遊戲類型（api.md §3.3 附錄）→ 平台遊戲類型
     *
     * 附錄六種取值皆為真人桌台細分類，平台無對應細分類，一律收斂為 LIVE_CASINO
     * （target/op_tickets#2866：奧林匹斯反水位配發依賴 type_id 正確分類）。
     */
    private const array GAME_TYPE_MAP = [
        4 => GameTypeEnum::LIVE_CASINO->value,   // 百家樂
        5 => GameTypeEnum::LIVE_CASINO->value,   // 輪盤
        10 => GameTypeEnum::LIVE_CASINO->value,  // 龍虎
        11 => GameTypeEnum::LIVE_CASINO->value,  // 骰寶
        14 => GameTypeEnum::LIVE_CASINO->value,  // 牛牛
        17 => GameTypeEnum::LIVE_CASINO->value,  // 三公
    ];

    protected string $accountModel = YbetAccount::class;

    protected string $betRecordModel = YbetBetRecord::class;

    private mixed $secretKey;

    private mixed $appId;

    private mixed $apiUrl;

    private mixed $memberId;

    private mixed $username;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        // Config
        $this->setInfo($this->vendorInfo->info);

        // Account
        $account = $this->vendorAccount;
        $this->memberId = $account->member_id ?? '';
        $this->username = $account->username ?? '';
    }

    public function setInfo(array $info): void
    {
        $this->apiUrl = $info['API_URL'];
        $this->appId = $info['APP_ID'];
        $this->secretKey = $info['API_KEY'];
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $data = [
            'appid' => $this->appId,
            'username' => $this->username,
            'iscreate' => 0, // 是否创建账号（1,是，0否）
            'clienttype' => 2, // 手机标识（1 pc，2 手机，3 ipad）
            'language' => $this->mapLanguageCode(),
        ];

        $data = $this->generateSignature($data);

        $result = $this->sendRequest('/login', $data);

        // {
        //     'result': 0,
        //     'desc': 'succeed',
        //     'openurl': 'https://example.invalid/direct/login/MTc0OTc5ODQ2OF8zMDBfMjY2NTEwOTFfRjdEQkI2?d=5&l=1'
        // }

        if ($result['result'] < 0) {
            $errorMessage = $this->mapErrorCode($result['result']);

            $this->throwError($errorMessage);
        }

        $gameUrl = $result['openurl'] ?? '';

        return VendorGameUrlResultData::from([
            'pc' => $gameUrl,
            'mobile' => $gameUrl,
        ]);
    }

    public function getBalance(): string
    {
        $data = [
            'appid' => $this->appId,
            'username' => $this->username,
        ];

        $data = $this->generateSignature($data);

        $result = $this->sendRequest('/user/balance', $data);

        if ($result['result'] < 0) {
            $errorMessage = $this->mapErrorCode($result['result']);

            $this->throwError($errorMessage);
        }

        return (string) $result['balance'];
    }

    /**
     * 玩家餘額轉入遊戲商 (存款)
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'appid' => $this->appId,
            'username' => $this->username,
            'tradeno' => $params->transactionNo,
            'amount' => $params->vendorAmount,
        ];

        $data = $this->generateSignature($data);

        try {
            $result = $this->sendRequest('/user/dw', $data);
        } catch (Exception $e) {
            Log::error($this->getName() . ' transferToVendor timeout', [
                'username' => $this->username,
                'response' => $e->getMessage() . ' (' . $e->getCode() . ')',
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error($this->getName() . ' transferToVendor failed: response is not array', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        if (!isset($result['result']) || (string) $result['result'] !== '0') {
            Log::error($this->getName() . ' transferToVendor failed: parameter "error" is not 0', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        if ((string) $result['result'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 轉出遊戲商餘額回本地 (提款)
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $data = [
            'appid' => $this->appId,
            'username' => $this->username,
            'tradeno' => $params->transactionNo,
            'amount' => '-' . $params->vendorAmount,
        ];

        $data = $this->generateSignature($data);

        try {
            $result = $this->sendRequest('/user/dw', $data);
        } catch (Exception $e) {
            Log::error($this->getName() . ' transferToLocal timeout', [
                'username' => $this->username,
                'response' => $e->getMessage() . ' (' . $e->getCode() . ')',
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $logData = [
            'request' => $data,
            'response' => $result,
        ];

        if (!is_array($result)) {
            Log::error($this->getName() . ' transferToVendor failed: response is not array', $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['result']) || (string) $result['result'] !== '0') {
            Log::error($this->getName() . ' transferToVendor failed: parameter "error" is not 0', $logData);

            return VendorTransferResultEnum::FAILED;
        }

        if ((string) $result['result'] === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 檢查轉帳結果
     */
    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $transactionLog = $this->getTransactionLog($transactionNo);

        $data = [
            'appid' => $this->appId,
            'username' => $this->username,
            'tradeno' => $transactionNo,
            'begintime' => $transactionLog->created_at->subSeconds(5)->timestamp,
            'endtime' => $transactionLog->created_at->addSeconds(5)->timestamp,
            'index' => 0,
            'size' => 1,
        ];

        $data = $this->generateSignature($data);

        try {
            $result = $this->sendRequest('/api/user/trade', $data);
        } catch (Exception $e) {
            Log::error($this->getName() . ' checkTransferStatus timeout', [
                'username' => $this->username,
                'response' => $e->getMessage() . ' (' . $e->getCode() . ')',
            ]);

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!is_array($result)) {
            Log::error($this->getName() . ' checkTransferStatus failed: response is not array');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (!isset($result['result'])) {
            Log::error($this->getName() . ' checkTransferStatus failed: parameter "result" is not found');

            return VendorTransferResultEnum::TIMEOUT;
        }

        // 0成功，-1失败
        if ((string) $result['result'] !== '0') {
            Log::error($this->getName() . ' checkTransferStatus failed: parameter "result" is not 0');

            return VendorTransferResultEnum::TIMEOUT;
        }

        if (isset($result['data'])) {
            $collection = collect($result['data']);

            // 檢查交易紀錄 tradeno 是否存在
            $record = $collection->firstWhere('tradeno', $transactionNo);

            // 状态（-1失败，1申请，2处理中，11成功）
            if (isset($record['status']) && (string) $record['status'] === '-1') {
                // 失败
                return VendorTransferResultEnum::FAILED;
            }

            if (isset($record['status']) && (string) $record['status'] === '11') {
                // 成功
                return VendorTransferResultEnum::SUCCESS;
            }
        }

        // 其餘錯誤都返回 TIMEOUT
        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 取得語言代碼
     */
    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value, LanguageEnum::ZH_CN->value => '1',
            LanguageEnum::TH_TH->value => '5',
            default => '2',
        };
    }

    /**
     * 錯誤代碼
     */
    private function mapErrorCode($errorCode): string
    {
        $errorCode = (string) $errorCode;

        return match ($errorCode) {
            '-1002' => '参数错误，请检查所传参数',
            '-1005' => 'Appid错误',
            '-1006' => 'Appid不存在',
            '-1007' => '签名错误，请检查签名',
            '-1008' => '过期的请求',
            '-1009' => '请求太频繁',
            '-1010' => '非法IP',
            '-1011' => '未注册，请先注册',
            '-1012' => '用户不存在',
            '-1013' => '用户已经存在',
            '-1014' => '用户被禁止',
            '-1015' => '用户密码错误',
            '-1020' => '用户余额不足',
            '-1021' => '存取款金额为非零数',
            '-1022' => '限额不存在',
            '-1030' => '扣款金额不足',
            '-1040' => '验证码错误',
            '-1041' => '非法域名',
            default => '系统未知异常',
        };
    }

    /**
     * 簽名規則
     */
    private function generateSignature(array $data): array
    {
        // 字段按照 参数名称 进行自然排序
        ksort($data);

        // 排序后的将参数名称按照 key=value 的方式组合，再由 & 字符进行拼接得出参数部分。
        $signature = http_build_query($data);

        // 有序组合将 参数部分 与给定的 Secret Key 组合。
        $signature .= '&key=' . $this->secretKey;

        // 最后将组合后的字符串进行MD5 加密，作为签名的值
        $signature = md5($signature);

        // 将签名值转换为小写
        // $signature = strtolower($signature);

        // 将该值设置为请求参数中的 sign 字段
        $data['sign'] = $signature;

        return $data;
    }

    /**
     * 遊戲清單（三方無圖片；遊戲由大廳進入、僅供對帳，比照舊系統以停用狀態建立）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        // sendRequest 錯誤路徑的 throwError 會讀 username，排程情境無會員先給空值
        $this->username = '';

        $data = [
            'appid' => $this->appId,
            'notifyid' => date('U'),
        ];
        $data = $this->generateSignature($data);

        $response = $this->sendRequest('/data/getgames', $data);

        if ((int) ($response['result'] ?? -1) !== 0) {
            throw new ApiException('Third party error: result=' . ($response['result'] ?? 'null'));
        }

        $gameList = [];
        $unknownTypes = [];
        foreach ($response['array'] ?? [] as $game) {
            $gameCode = (string) $game['id'];
            $rawType = (int) ($game['gametype'] ?? 0);
            $gameType = self::GAME_TYPE_MAP[$rawType] ?? self::LEGACY_UNCATEGORIZED_TYPE_ID;

            if (!isset(self::GAME_TYPE_MAP[$rawType])) {
                $unknownTypes[$rawType] = $gameCode;
            }

            $gameList[$gameCode] = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['title'],
                'imageUrl' => '',
                'gameType' => $gameType,
                'status' => 0,
                'info' => json_encode($game),
            ]);
        }

        if (!empty($unknownTypes)) {
            Log::warning('Yeebet 遊戲清單出現對應表外的遊戲類型', [
                'vendor_info_id' => $params->vendorInfo->id,
                'unknown_types' => $unknownTypes,
            ]);
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    private function sendRequest(string $endpoint, array $data): mixed
    {
        $backtrace = debug_backtrace();
        $previousMethod = $backtrace[1]['function'] ?? null;
        $action = $this->getName() . ' ' . $previousMethod;

        $apiUrl = rtrim($this->apiUrl, '/');
        $url = $apiUrl . $endpoint;

        // 雖然是 post 請求, 但必須把參數組合到網址而不是 body 中
        $url .= '?' . http_build_query($data);

        try {
            $result = Http::logAction($action)
                ->asJson()
                ->post($url)
                ->throw()
                ->json();
        } catch (Exception $e) {
            $this->throwError('API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function throwError(string $message, array $context = []): void
    {
        $vendorName = $this->getName();

        $context = array_merge([
            'vendor_id' => $this->vendor->id,
            'username' => $this->username,
        ], $context);

        Log::error("$vendorName $message", $context);

        throw new ApiException($message, HttpCode::HTTP_INTERNAL_SERVER_ERROR->value);
    }

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_ybet_account）
     *
     * getGameUrl 帶 iscreate=0（不自動建號），帳號必須先在三方 /register 開好。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        // sendRequest 錯誤路徑的 throwError() 會讀 $this->username，排程情境無會員先注入
        $this->username = $username;

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        if (!$this->createVendorAccount($username)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號（/register）
     */
    private function createVendorAccount(string $username): bool
    {
        $data = [
            'appid' => $this->appId,
            'username' => $username,
            // 限額 ID，未設定時比照舊系統預設 10
            'quotas' => $this->vendorInfo->info['LIMIT'] ?? 10,
        ];

        $data = $this->generateSignature($data);

        try {
            $result = $this->sendRequest('/register', $data);
        } catch (Throwable $e) {
            Log::error('[Ybet][createAccount] request failed', [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($result) || (int) ($result['result'] ?? -1) !== 0) {
            Log::error('[Ybet][createAccount] failed', [
                'username' => $username,
                'error_message' => isset($result['result']) ? $this->mapErrorCode($result['result']) : null,
                'response' => $result,
            ]);

            return false;
        }

        return true;
    }
}
