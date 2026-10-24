<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Acewin;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\AcewinAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class AcewinTestCase extends TestCase
{
    protected string $baseurl = '/external/api/sw/acewin';

    /**
     * 商戶識別憑證（明顯假值）。AcewinSW 以 HTTP Basic Auth 帶回，
     * 本平台 `parseOperatorId()` 解碼後比對 `vendor_infos.info.CLIENT_AUTH_ACCOUNT`。
     */
    protected string $clientAuthAccount = 'acewin-test:not-a-real-secret';

    protected string $username = 'acewin_00000001';

    protected string $gameCode = '1001';

    protected string $sessionToken = [REDACTED]';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected AcewinAccount $account;

    protected Member|MemberWallet $wallet;

    protected ?MemberWallet $rollingChipWallet = null;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::ACEWIN->value);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = 'MYR';
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = [
            'AUTH_URL' => '',
            'CURRENCY' => 'MYR',
            'CLIENT_ID' => 'acewin-test',
            'CLIENT_CLASS' => 'test',
            'CLIENT_SECRET' => 'not-a-real-secret',
            'CLIENT_AUTH_ACCOUNT' => $this->clientAuthAccount,
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->account = AcewinAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'member_id' => $this->member->id,
            'status' => 1,
            'vendor_username' => $this->username,
            'session_token' => $this->sessionToken,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
    }

    /**
     * AcewinSW 以 Basic Auth 帶商戶憑證（api.md 附錄 E）
     *
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Basic ' . base64_encode($this->clientAuthAccount)];
    }

    /**
     * bet 回調的請求內容。欄位取自 TPower 生產原文（`ext-webapi-container` 的
     * `api/sw/acewin/bet 請求紀錄`，2026-09-20 20:00 UTC），與 api.md 的差異照生產為準：
     *
     * - **不帶 `userId`**，只帶 `token`——會員一律走 session token 查；cancelBet 才帶 `userId`。
     * - 另帶 `id`，值與 `round` 相同。
     * - `game` 是整數，`betAmount` / `winloseAmount` 是 float，不是字串。
     *
     * @return array<string, mixed>
     */
    protected function betPayload(string $round, string $betAmount, string $winloseAmount): array
    {
        return [
            'reqId' => 'req-' . $round,
            'currency' => 'MYR',
            'game' => (int) $this->gameCode,
            'id' => $round,
            'round' => $round,
            'betAmount' => (float) $betAmount,
            'winloseAmount' => (float) $winloseAmount,
            'wagersTime' => now()->timestamp,
            'token' => $this->sessionToken,
        ];
    }

    /**
     * cancelBet 回調的請求內容。同樣取自 TPower 生產原文（`api/sw/acewin/cancelBet 請求紀錄`）：
     * 與 bet 相反，**帶 `userId`**、**不帶 `wagersTime`**，且 `winloseAmount` 可能非零
     * （生產樣本 betAmount=0.1、winloseAmount=0.08）——撤單一律歸零，該欄位不參與計算。
     *
     * @return array<string, mixed>
     */
    protected function cancelBetPayload(string $round, string $betAmount, string $winloseAmount = '0'): array
    {
        return [
            'reqId' => 'req-cancel-' . $round,
            'currency' => 'MYR',
            'game' => (int) $this->gameCode,
            'id' => $round,
            'round' => $round,
            'betAmount' => (float) $betAmount,
            'winloseAmount' => (float) $winloseAmount,
            'userId' => $this->username,
            'token' => $this->sessionToken,
        ];
    }
}
