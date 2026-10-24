<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WudiSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\WudiswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class WudiSWTestCase extends TestCase
{
    protected $username = 'TESTWUDISW00000001';

    protected $baseurl = '/external/api/sw/wudi';

    protected $gameCode = 'WG008';

    protected Vendor $vendor;

    protected ?VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected WudiswAccount $account;

    protected MemberWallet|Member $wallet;

    protected MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::WUDISW->value);
        $this->vendor->name = 'Wudi SW';
        $this->vendor->code = 'WUDISW';
        $this->vendor->tname = 'wudisw';
        $this->vendor->third_party_code = 'wudisw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'wudisw';
        $this->vendorInfo->account_prefix = VendorIdEnum::WUDISW->value . '_';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = json_encode([
            'API_URL' => '',
            'ACCOUNT_ID' => '',
            'MD5_KEY' => '',
            'SECURITY_CODE' => '',
        ]);
        $this->vendorInfo->save();

        $game = $this->vendor->games()->first();
        $game->vendor_id = $this->vendor->id;
        $game->game_type = $this->gameCode;
        $game->status = 1;
        $game->save();

        $this->account = WudiswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => 0,
        ]);

        $this->wallet = $this->member;
    }

    protected function enableRollingChip()
    {
        $this->vendorInfo->update(['rolling_chip_status' => true]);
    }
}
