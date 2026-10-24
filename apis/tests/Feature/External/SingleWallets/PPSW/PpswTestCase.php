<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\PpswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class PpswTestCase extends TestCase
{
    protected string $providerId = 'PragmaticPlay';

    protected string $secretKey = 'testSecretKey';

    protected string $username = 'ppsw00004595';

    protected string $gameCode = '902a9';

    protected string $baseurl = '/external/api/sw/pp';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected PpswAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PPSW->value);
        $this->vendor->name = 'PPSW';
        $this->vendor->code = 'ppsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = VendorIdEnum::PPSW->value . '_PPSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'PROVIDER_ID' => $this->providerId,
            'SECRET_KEY' => $this->secretKey,
        ];
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->account = PpswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'currency' => 'CNY',
            'country' => 'CN',
            'status' => 1,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
        $this->wallet = $walletDto->wallet;
    }

    /**
     * 依 PpswController::calculateHash 的規則計算 hash：
     * 將參數（去除 hash 欄位）按 key 排序，組成 key=value&... 字串，串接 secretKey 後 md5。
     */
    protected function calculateHash(array $params): string
    {
        unset($params['hash']);
        ksort($params);

        return md5(http_build_query($params) . $this->secretKey);
    }

    /**
     * 自動補上 hash 欄位
     */
    protected function withHash(array $params): array
    {
        $params['hash'] = $this->calculateHash($params);

        return $params;
    }
}
