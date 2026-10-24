<?php

declare(strict_types=1);

namespace App\Vendors\JokerBaccaratSW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\JkbswAccount;
use App\Vendors\Models\JkbswBetRecord;
use Illuminate\Support\Str;

class Driver extends AbstractSingleWallet implements HasCallbackPath, ShouldScheduleAccountCreation
{
    use JokerBaccaratSWTrait;

    protected string $accountModel = JkbswAccount::class;

    protected string $betRecordModel = JkbswBetRecord::class;

    protected string $vendorLabel = 'JokerBaccaratSW';

    private VendorInfoDetail $infoDetail;

    public function __construct(int $vendorId, ?Member $member = null)
    {
        parent::__construct($vendorId, $member);

        if (!$member) {
            return;
        }

        $this->setInfo($this->vendorInfo->info);
    }

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 jokerbaccarat 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/jokerbaccarat';
    }

    public function setInfo(array $info): void
    {
        $this->infoDetail = VendorInfoDetail::from($info);
    }

    /**
     * 回傳 DB 上 username 的真實前綴（供排程 getLatestAccountNumber 反算最大序號）。
     *
     * Joker 在 register 時自動為 playerID 加上「代理 portal 端設定的 account prefix」
     * （見 docs/integrations/vendors/JokerBaccarat/api.md §1）。此前綴由 Joker 端
     * 決定、未必等於登入 API_ID（例：API_ID=jbtpower、實際回填前綴=JBT），故不可用
     * API_ID 推導，否則 LIKE 撈不到既有帳號、序號歸零後重撞 Joker 既有 playerID 死循環。
     * 改以「既有帳號的實際 username」反推：取最近一筆「前綴 + 8 碼數字序號」形態的帳號，
     * 去掉尾 8 碼即真實前綴。
     *
     * 表為空（首次建帳）時無從反推 → 退回 account_prefix；此時尚無 row 需被 LIKE 命中，
     * getLatestAccountNumber 本就回 0，前綴值不影響結果。
     */
    public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
    {
        $usernames = $this->accountModel::query()
            ->where('vendor_info_id', $vendorInfo->id)
            ->orderByDesc('id')
            ->limit(20)
            ->pluck('username');

        foreach ($usernames as $username) {
            $username = (string) $username;

            // 長度 > 8 排除尚未被三方回填的 transient bare row；尾 8 碼為純數字才視為合法序號帳號
            if (strlen($username) > 8 && ctype_digit(substr($username, -8))) {
                return substr($username, 0, -8); // 'JBT00000014' → 'JBT'
            }
        }

        return (string) $vendorInfo->account_prefix;
    }

    /**
     * 建立遊戲商帳號（依 game-integration §3.2「有三方 API 的標準寫法」）
     */
    public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
    {
        $this->setVendorInfo($params->vendorInfo);

        // 失敗時內部遞增序號重試：避免下次排程跑同樣序號（getLatestAccountNumber 看不到失敗 row）
        // 再次撞 Joker 的 duplicate playerID 死循環。
        // 上限避免 Joker 端壞掉時瘋狂打 API；超過視為徹底失敗。
        $maxRetries = 10;
        $prefix = (string) $this->vendorInfo->account_prefix;
        $startSerial = (int) substr($params->username, strlen($prefix));

        for ($i = 0; $i < $maxRetries; $i++) {
            $serial = $startSerial + $i;
            $username = $prefix . str_pad((string) $serial, 8, '0', STR_PAD_LEFT);
            $password = [REDACTED];

            $account = $this->accountModel::create([
                'vendor_info_id' => $this->vendorInfo->id,
                'username' => $username,
                'password' => $password,
            ]);

            if (!$account) {
                // create() 在嚴格模式下幾乎不可能回 null，但保險起見視為此序號 fail，往下試
                continue;
            }

            $registered = $this->registerPlayer($username, $password);

            if (!$registered) {
                // 直接刪掉失敗 row（留 row 會擋下次排程序號 + 撞 uniq_jkbsw_username 死循環）。
                // 審計痕跡靠 Log::error('sendRequest failed', ...) 留存。
                $account->delete();

                continue;
            }

            // Joker 在 playerID 前加上 portal 設定的 prefix，以三方回傳值為準（必須用回傳值，否則後續 API 會回 "player not found"）
            $finalPlayerId = (string) ($registered['playerID'] ?? $username);
            if ($finalPlayerId !== '' && $finalPlayerId !== $username) {
                $account->update(['username' => $finalPlayerId]);
            }

            return CreateAccountResultData::from([
                'status' => true,
                'username' => $finalPlayerId,
            ]);
        }

        return CreateAccountResultData::from([
            'status' => false,
            'username' => $prefix . str_pad((string) $startSerial, 8, '0', STR_PAD_LEFT),
        ]);
    }

    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
    {
        $payload = [
            'playerID' => $this->vendorAccount->username,
            'category' => 'all',
            'redirectURL' => $params->exitUrl,
            'lang' => $this->mapLanguage($params->language),
        ];

        $response = $this->sendRequest('/merchant/game/open-lobby', $payload);

        if (!($response['status'] ?? false)) {
            throw new ApiException(
                '[' . $this->vendorLabel . '][getGameUrl] ' . ((string) ($response['message'] ?? 'Unknown error'))
            );
        }

        $url = (string) ($response['data'] ?? '');
        if ($url === '') {
            throw new ApiException('[' . $this->vendorLabel . '][getGameUrl] empty URL in response');
        }

        return VendorGameUrlResultData::from([
            'pc' => $url,
            'mobile' => $url,
        ]);
    }

    /**
     * 呼叫 Joker /merchant/player/register；成功回傳 data 物件，失敗（含 HTTP 4xx）回傳 null。
     *
     * Joker 對 duplicate playerID 回 HTTP 400 + body status:false，
     * 上游 sendRequest 會把 4xx/5xx 轉成 ApiException，這裡必須 catch 才能讓 createAccount
     * 的 retry loop 正確收斂（否則 throw 會繞過 retry，留下 transient row 並中斷流程）。
     */
    protected function registerPlayer(string $playerID, string $password): ?array
    {
        try {
            $response = $this->sendRequest('/merchant/player/register', [
                'playerID' => $playerID,
                'password' => $password,
            ]);
        } catch (ApiException) {
            // sendRequest 已 Log::error 詳細 payload + response，這裡靜默回 null 由 caller 重試
            return null;
        }

        if (!($response['status'] ?? false)) {
            return null;
        }

        $data = $response['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    private function mapLanguage(LanguageEnum $language): string
    {
        return match ($language) {
            LanguageEnum::EN => 'EN',
            LanguageEnum::ZH_TW, LanguageEnum::ZH_CN => 'CN',
            LanguageEnum::MS_MY, LanguageEnum::ID_ID => 'MY',
            LanguageEnum::TH_TH => 'TH',
            LanguageEnum::VI_VN => 'VN',
            LanguageEnum::MY_MM => 'MM',
            default => 'EN',
        };
    }
}
