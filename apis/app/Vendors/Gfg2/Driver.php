<?php

declare(strict_types=1);

namespace App\Vendors\Gfg2;

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
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Gfg2Account;
use App\Vendors\Models\Gfg2BetRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Throwable;

/**
 * @property Gfg2Account $vendorAccount
 */
class Driver extends AbstractTransferWallet implements ShouldScheduleAccountCreation, ShouldSyncGameList
{
    /**
     * 舊系統未分類遊戲寫 type_id=0（共用 DB 既有慣例，維持一致）
     */
    private const int LEGACY_UNCATEGORIZED_TYPE_ID = 0;

    private const string GAME_IMAGE_URL = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/vworld/game/gfg2/en_us/';

    protected string $accountModel = Gfg2Account::class;

    protected string $betRecordModel = Gfg2BetRecord::class;

    private VendorInfoDetail $infoDetail;

    private string $vendorUsername;

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetail::from($info);
        // 排程情境（如遊戲清單同步）無會員也無 vendorAccount，帳號留空
        $this->vendorUsername = isset($this->vendorAccount)
            ? ($this->vendorAccount->vendor_username ?? $this->infoDetail->CLIENT_CLASS . $this->vendorAccount->username)
            : '';
    }

    /**
     * 遊戲清單（Authorization = md5(body json + CLIENT_SECRET)；
     * 圖檔照舊系統走 GCS 共用圖庫（vworld bucket 硬編碼）；gameName 為簡中，僅寫簡中語系名）
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        $data = [
            'companyKey' => $this->infoDetail->CLIENT_ID,
            'agent' => $this->infoDetail->CLIENT_AGENT,
            'timestamp' => (int) floor(microtime(true) * 1000),
        ];

        $response = Http::logAction('Gfg2_GetGameList')
            ->asJson()
            ->withHeaders([
                'Authorization' => md5(json_encode($data) . $this->infoDetail->CLIENT_SECRET),
            ])
            ->post("{$this->infoDetail->AUTH_URL}/getGameList", $data)
            ->throw()
            ->json();

        if (!is_array($response) || (int) ($response['code'] ?? -1) !== 0) {
            throw new ApiException('Third party error: (' . ($response['code'] ?? 'null') . ') ' . ($response['msg'] ?? ''));
        }

        $typeMap = [
            'Slot' => GameTypeEnum::SLOTS->value,
            'Chess' => GameTypeEnum::POKER->value,
            'Fish' => GameTypeEnum::FISHING->value,
        ];

        $gameList = [];
        foreach ($response['data']['games'] ?? [] as $game) {
            $gameCode = (string) $game['gameId'];
            $gameInfo = GameInfoData::from([
                'gameCode' => $gameCode,
                'gameName' => $game['gameName'],
                'imageUrl' => self::GAME_IMAGE_URL . $gameCode . '.png',
                'gameType' => $typeMap[$game['gameType'] ?? ''] ?? self::LEGACY_UNCATEGORIZED_TYPE_ID,
                'status' => 1,
                'info' => json_encode($game),
            ]);
            $gameInfo->setGameNames(LanguageEnum::ZH_CN->value, $game['gameName']);
            $gameList[$gameCode] = $gameInfo;
        }

        return GetGameListResultData::from(['gameList' => $gameList]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = 'GetGameUrl';

        $response = $this->apiCall('login', $action, [
            'gameId' => (int) $params->gameCode,
            'ip' => Common::getIp(),
            'platform' => $params->deviceType === DeviceTypeEnum::MOBILE ? 1 : 0, // 设备(PC 0, Wap=1, App=2)
            'languageType' => $this->mapLanguageCode($params->language),
        ]);

        if (!is_array($response) || !isset($response['code'])) {
            throw new ApiException("[Gfg2][$action] API error: bad response.");
        }

        $code = (string) $response['code'];
        if ($code !== '0') {
            throw new ApiException("[Gfg2][$action] API error: status code is not correct.");
        }

        if (!isset($response['data']['url'])) {
            throw new ApiException("[Gfg2][$action] API error: missing data.");
        }

        return new VendorGameUrlResultData(
            pc: $response['data']['url'],
            mobile: $response['data']['url'],
        );
    }

    public function getBalance(): string
    {
        $action = 'GetBalance';
        $response = $this->apiCall('queryUserScore', $action);

        if (!is_array($response) || !isset($response['code'])) {
            throw new ApiException("[Gfg2][$action] API error: bad response.");
        }

        $code = (string) $response['code'];
        if ($code !== '0') {
            throw new ApiException("[Gfg2][$action] API error: status code is not correct.");
        }

        if (!isset($response['data']['money']) || !is_numeric($response['data']['money'])) {
            throw new ApiException("[Gfg2][$action] API error: missing data.");
        }

        return Common::numFloor($response['data']['money']);
    }

    public function checkTransferStatus(string $transactionNo): VendorTransferResultEnum
    {
        $action = 'CheckTransferStatus';
        $data = [
            'orderId' => $transactionNo,
        ];
        $response = $this->apiCall('queryTransferOrder', $action, $data);

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response) || !isset($response['code'])) {
            Log::error("[Gfg2][$action] API error: bad response.", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $code = (string) $response['code'];
        if ($code === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        if (in_array($code, $this->acceptedErrorCodes(), true)) {
            Log::error("[Gfg2][$action] API error: failed.", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        Log::error("[Gfg2][$action] API error: status code is not correct.", $logData);

        return VendorTransferResultEnum::TIMEOUT;
    }

    /**
     * 玩家餘額轉入遊戲商 (存款), origin: type = 1
     */
    public function transferToVendor(VendorTransferData $params): VendorTransferResultEnum
    {
        return $this->transfer($params, 'doTransferDepositTask', 'TransferToVendor');
    }

    /**
     * 轉出遊戲商餘額回本地 (提款), origin: type != 1
     */
    public function transferToLocal(VendorTransferData $params): VendorTransferResultEnum
    {
        $params->vendorAmount = Common::numMul($params->vendorAmount, -1);

        return $this->transfer($params, 'doTransferWithdrawTask', 'TransferToLocal');
    }

    private function transfer(VendorTransferData $params, string $endPoint, string $action): VendorTransferResultEnum
    {
        // 金額必須只有小數點兩位
        $money = Common::numFloor($params->vendorAmount, 2);
        // 金額必須是正數
        $money = abs((float) $money);

        $data = [
            'orderId' => $params->transactionNo,
            'money' => $money,
        ];
        $response = $this->apiCall($endPoint, $action, $data);

        $logData = [
            'request' => $data,
            'response' => $response,
        ];

        if (!is_array($response) || !isset($response['code'])) {
            Log::error("[Gfg2][$action] API error: bad response.", $logData);

            return VendorTransferResultEnum::TIMEOUT;
        }

        $code = (string) $response['code'];
        if ($code === '0') {
            return VendorTransferResultEnum::SUCCESS;
        }

        if (in_array($code, $this->acceptedErrorCodes(), true)) {
            Log::error("[Gfg2][$action] API error: failed.", $logData);

            return VendorTransferResultEnum::FAILED;
        }

        Log::error("[Gfg2][$action] API error: status code is not correct.", $logData);

        return VendorTransferResultEnum::TIMEOUT;
    }

    public function getTransactionNo(?TransactionEnum $transactionEnum = null): string
    {
        $datetime = Carbon::now()->setTimezone('Asia/Taipei')->format('YmdHisu'); // +8

        return $this->infoDetail->CLIENT_AGENT . $datetime . $this->vendorUsername;
    }

    private function apiCall(string $endPoint, string $action, array $data = []): mixed
    {
        $url = "{$this->infoDetail->AUTH_URL}/{$endPoint}";
        $timestamp = floor(microtime(true) * 1000);

        $data = array_merge([
            'agent' => $this->infoDetail->CLIENT_AGENT,
            'timestamp' => $timestamp,
            'account' => $this->vendorUsername,
        ], $data);

        $authToken = [REDACTED] . $this->infoDetail->CLIENT_SECRET);

        return Http::logAction("Gfg2_{$action}")
            ->withHeaders([
                'Authorization' => "{$authToken}",
            ])
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();
    }

    private function mapLanguageCode(?LanguageEnum $language = null): string
    {
        if (empty($language)) {
            $languageId = Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value);
        } else {
            $languageId = $language->value;
        }

        return match ($languageId) {
            LanguageEnum::ZH_TW->value => 'zh_tw',
            LanguageEnum::ZH_CN->value => 'zh_cn',
            LanguageEnum::VI_VN->value => 'vi_vn',
            LanguageEnum::TH_TH->value => 'th_th',
            LanguageEnum::TA->value => 'en_in',
            default => 'en_us',
        };
    }

    private function acceptedErrorCodes(): array
    {
        return [
            '1',      // 失败（登陆时则是账号不存在）
            '2',      // 订单正在处理中
            '3',      // 订单不存在
            '4',    // 签名验证未通过
            '5',    // 订单重复
            '6',    // 余额不足，下分失败
            '7',    // 请求过于频繁
            '8',    // 游戏中，不能上下分
            '9',    // 游戏已关闭
            '10',    // 非法参数
            '11',    // 失败
            '12',    // 后台验证失败
            '13',    // 非法的公司key
            '14',    // 调用登录注册接口返回未知错误码0
            '16',     // 找不到 provider 或 provider 未初始化
            '17',     // 找不到 对应的货币代码：没有为当前货币代码映射到第三方，需要提供第三方游戏   厂商的货币代码。
            '18',     // API找不到對應資料的通用代碼
            '403',    // 禁止访问
            '404',    // 找不到服务器
            '503',    // 服务器内部错误，例如数据库执行出错等
            '504',    // 服务器已关闭
            '601',    // 玩家数据异常，需要重新登录
            '602',    // 请求太频繁了
            '701',    // 无效签名
            '702',    // 请求参数非法
            '703',    // 获取的数据非法
            '704',    // 非法的公司ID
            '705',    // 无效的钱包类型
            '1001',   // 无效的MAC地址
            '1002',   // 无效的第三方参数
            '1003',   // IP地址被封禁
            '1004',   // MAC地址被封禁
            '1005',   // 账户已被停用，请联系客服
            '1006',   // 玩家不存在(uid或账号不存在)
            '1007',   // 密码错误
            '1009',   // 无效的登录类型
            '1010',   // 无效的手机信息
            '1011',   // 注册失败，账号已存在
            '1012',   // 注册失败，参数错误
            '1013',   // 注册密码不符合规则
            '1014',   // 非法的账号（不符合账号规则）
            '1015',   // 账号重复登录
            '1016',   // 不在白名单内，禁止登录
            '1017',   // 验证失败
            '1018',   // token失效
            '1019',   // 公司ID错误
            '1020',   // 平台生成账号失败
            '1021',   // 账号为空
            '1022',   // 验证码为空
            '1023',   // 由于输入密码多次错误，账户已被限制若干分钟内不能登录。请稍候再试或联系客服！
            '1024',   // 账号或密码错误
            '1025',   // 登录异常，请联系在线客服
            '1026',   // 用户名格式不正确
            '1027',   // 用户名连续出现四个及以上的相同字母或数字
            '1029',   // 您当前区域被限制登录
            '10001',  // game id 不存在
        ];
    }

    /**
     * 建號用 IP：三方要求必填，排程情境無 request，沿用舊系統的公司固定 IP
     */
    private const string CREATE_ACCOUNT_IP = '0.0.0.0';

    /**
     * 建立我方遊戲商帳號（遷自 RD-Studio create_gfg2_account）
     *
     * 三方無獨立註冊 API，以 /login 帶 gameId 首次登入即建號。
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);
        $username = $params->username;
        $vendorUsername = $this->infoDetail->CLIENT_CLASS . $username;
        $sessionToken = [REDACTED]'-', '', (string) Str::uuid()) . bin2hex(random_bytes(32));

        $account = $this->accountModel::create([
            'vendor_info_id' => $params->vendorInfo->id,
            'username' => $username,
            // vendor_username 是注單 JOIN 鍵且 NOT NULL 無 default
            'vendor_username' => $vendorUsername,
            'session_token' => $sessionToken,
            'admin' => 1,
            'member_id' => -999999999,
        ]);

        if (!$account) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        // apiCall 送出的 account 取自 $this->vendorUsername；setInfo 在無 vendorAccount 時
        // 只會給空字串，建號情境必須手動補上，否則會送出 account=''
        $this->vendorUsername = $vendorUsername;

        if (!$this->createVendorAccount($username, $sessionToken)) {
            return CreateAccountResultData::from(['status' => false, 'username' => $username]);
        }

        $account->update(['member_id' => 0]);

        return CreateAccountResultData::from(['status' => true, 'username' => $username]);
    }

    /**
     * 建立三方遊戲商帳號
     */
    private function createVendorAccount(string $username, string $sessionToken): bool
    {
        $action = 'CreateAccount';

        try {
            $response = $this->apiCall('login', $action, [
                'nickName' => $username, // 裸帳號，不含 CLIENT_CLASS
                'gameId' => (int) $this->infoDetail->CLIENT_DEFAULT_GAME_ID_FOR_CREATE_ACCOUNT,
                'theme' => $this->infoDetail->CLIENT_THEME,
                'ip' => self::CREATE_ACCOUNT_IP,
                'platform' => 0, // 設備（PC 0、Wap 1、App 2）
                'languageType' => 'en_us',
                'lobbyType' => 0,
                'token' => $sessionToken,
            ]);
        } catch (Throwable $e) {
            Log::error("[Gfg2][{$action}] API error", [
                'username' => $username,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            return false;
        }

        if (!is_array($response) || (string) ($response['code'] ?? '') !== '0') {
            Log::error("[Gfg2][{$action}] API error: code is not 0", [
                'username' => $username,
                'response' => $response,
            ]);

            return false;
        }

        return true;
    }
}

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $AUTH_URL,
        public string $CLIENT_SECRET,
        public string $CLIENT_DEFAULT_GAME_ID_FOR_CREATE_ACCOUNT,
        public string $CLIENT_AGENT,
        public string $CLIENT_THEME,
        public string $CLIENT_CLASS,
        public string $CURRENCY,

        public ?string $CLIENT_ID = null, // 2024-11-01 文檔刪除參數 companyKey
    ) {}
}
