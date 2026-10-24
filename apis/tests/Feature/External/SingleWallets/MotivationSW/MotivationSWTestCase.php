<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\MotivationswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MotivationSWTestCase extends TestCase
{
    protected string $baseurl = '/external/api/sw/motivation';

    protected string $wtoken = [REDACTED]';

    protected string $username = 'test_motivation_user';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Game $game;

    protected Member $member;

    protected MotivationswAccount $account;

    protected Member|MemberWallet $wallet;

    protected ?MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::MOTIVATIONSW->value);
        $this->vendor->name = 'MotivationSW';
        $this->vendor->code = 'motivationsw';
        $this->vendor->tname = 'motivationsw';
        $this->vendor->third_party_code = 'motivationsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'motivationsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::MOTIVATIONSW->value . '_MSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0;
        $this->vendorInfo->currency = 'HKD';
        $this->vendorInfo->rolling_chip_status = false;
        $this->vendorInfo->info = json_encode([
            'apiUrl' => 'http://example.invalid',
            'token' => $this->wtoken,
        ]);
        $this->vendorInfo->save();

        $game = $this->vendor->games()->first();
        $this->assertInstanceOf(Game::class, $game);
        $this->game = $game;
        $this->game->game_type = 'test_game_001';
        $this->game->status = 1;
        $this->game->save();

        $this->account = MotivationswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        $walletDatas = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDatas->wallet;
        $this->rollingChipWallet = $walletDatas->rollingChipWallet;
    }

    protected function wtokenHeader(): array
    {
        return ['wtoken' => $this->wtoken];
    }
}
