<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\PtswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class PtswTestCase extends TestCase
{
    protected string $username = 'ptsw00000001';

    protected string $token = [REDACTED]';

    protected string $baseurl = '/external/api/sw/pt';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected PtswAccount $account;

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

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PTSW->value);
        $this->vendor->name = 'PTSW';
        $this->vendor->code = 'ptsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = VendorIdEnum::PTSW->value . '_PTSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = [
            'API_URL' => '',
            'COUNTRY_CODE' => 'CN',
        ];
        $this->vendorInfo->save();

        // live_ 開頭的 gameRoundCode 對應 game_type='ubal'（PlayTechController L210）
        $this->game = $this->vendor->games()->first();
        $this->game->game_type = 'ubal';
        $this->game->status = 1;
        $this->game->save();

        $this->account = PtswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'status' => 1,
            'admin' => 1,
            'member_id' => $this->member->id,
            'token' => $this->token,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
        $this->wallet = $walletDto->wallet;
    }
}
