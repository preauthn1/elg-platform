<?php

declare(strict_types=1);

namespace App\Vendors\Nex4DSW;

use App\Data\GameInfoData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Member;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Models\Nex4dswAccount;
use App\Vendors\Models\Nex4dswBetRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @property Nex4dswAccount $vendorAccount
 */
class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation, ShouldSyncGameList
{
    protected string $vendorName = 'NEX4DSW';

    protected string $accountModel = Nex4dswAccount::class;

    protected string $betRecordModel = Nex4dswBetRecord::class;

    protected array $config = [];

    public function __construct(int $vendorId, ?Member $member)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 nex4d 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/nex4d';
    }

    public function setInfo(array $info): void
    {
        /**
         * API_URL
         * REPORT_API_URL
         * AGENT_CODE
         * OP_CODE
         * SECRET_KEY
         */
        $this->config = $info;
    }

    /**
     * Request SSO BO Login URL
     */
    public function getBoUrl(): string
    {
        $action = $this->vendorName . '_getBoUrl';

        $data = [
            'agentCode' => $this->config['AGENT_CODE'],
            'username' => $this->config['AGENT_CODE'],
            'ipAddress' => Common::getIp(),
            'reqDateTime' => Carbon::now()->format('Y-m-d H:i:s'),
            'opCode' => $this->config['OP_CODE'],
        ];

        $data['securityToken'] = md5($data['agentCode'] . $data['username'] . $data['ipAddress'] . $data['reqDateTime'] . $data['opCode'] . $this->config['SECRET_KEY']);

        $url = $this->config['ADMIN_API_URL'] . '/requestBOLoginUrl';

        $result = Http::logAction($action, $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if ($result['errorCode'] === '0') {
            return $result['url'] . $this->config['AGENT_CODE'];
        }

        return $result['errorDesc'];

    }

    /**
     * 建立我方遊戲商帳號
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        // 取目前帳號最大值
        $latestNum = $params->serialNumber;

        // 注入遊戲商戶資料
        $this->setVendorInfo($params->vendorInfo);
        $prefix = $this->vendorInfo->account_prefix;

        // 帳號規則，前綴加上8位數字
        $username = $prefix . str_pad((string) ($latestNum + 1), 8, '0', STR_PAD_LEFT);

        $data = [
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $username,
        ];
        $account = $this->accountModel::create($data);

        if (!$account) {
            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        // 呼叫三方 API 建立遊戲商帳號
        if (!$this->createVendorAccount($account)) {
            // 如果失敗就刪除這筆記錄
            $account->update(['member_id' => -999999999]);

            return CreateAccountResultData::from([
                'status' => false,
                'username' => $username,
            ]);
        }

        return CreateAccountResultData::from([
            'status' => true,
            'username' => $username,
        ]);
    }

    /**
     * 2.0 Register Player
     * 建立三方遊戲商帳號
     */
    protected function createVendorAccount($account): bool
    {
        $action = $this->vendorName . '_createAccount';

        $data = [
            'username' => $account->username,
            'reqDateTime' => Carbon::now()->format('Y-m-d H:i:s'),
            'opCode' => $this->config['OP_CODE'],
        ];

        $data['securityToken'] = md5($data['username'] . $data['reqDateTime'] . $data['opCode'] . $this->config['SECRET_KEY']);

        $url = $this->config['API_URL'] . '/registerPlayer';

        $result = Http::logAction($action, $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result)) {
            return false;
        }

        if ((string) $result['errorCode'] === '0') {
            return true;
        }

        Log::error('NEX4DSW createVendorAccount failed', ['response' => $result]);

        return false;
    }

    /**
     * 遊戲清單 (寫死一組大廳)
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData
    {
        $gameCode = 'lobby';
        $data = [];
        $newGame = GameInfoData::from([
            'gameCode' => $gameCode,
            'gameName' => $gameCode,
            'imageUrl' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/games/Nex4D/logo.png',
            'gameType' => 4, // 彩票
            'status' => 1,
        ]);
        $data[$gameCode] = $newGame;

        return GetGameListResultData::from([
            'gameList' => $data,
        ]);
    }

    /**
     * 3.0 Player Login
     */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $action = $this->vendorName . '_getGameUrl';

        $data = [
            'agentCode' => $this->config['AGENT_CODE'],
            'username' => $this->vendorAccount->username,
            'reqDateTime' => Carbon::now()->format('Y-m-d H:i:s'),
            'opCode' => $this->config['OP_CODE'],
        ];

        $data['securityToken'] = md5($data['agentCode'] . $data['username'] . $data['reqDateTime'] . $data['opCode'] . $this->config['SECRET_KEY']);

        $url = $this->config['API_URL'] . '/playerLogin';

        $result = Http::logAction($action, $data)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        if (!is_array($result)) {
            throw new ApiException('The three-party response format is incorrect.');
        }

        if ((string) $result['errorCode'] !== '0') {
            throw new ApiException(sprintf('The three-party response error:  (%s) %s', $result['errorCode'], $result['errorDesc']));
        }

        // update player token (好像不重要)
        $this->vendorAccount->token = [REDACTED]'loginToken'] ?? '';
        $this->vendorAccount->save();

        return VendorGameUrlResultData::from([
            'mobile' => $result['launchUrl'],
            'pc' => $result['launchUrl'],
        ]);
    }
}
