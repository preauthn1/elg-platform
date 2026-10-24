<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TFGamingSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\TfgamingswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class TFGamingSWTestCase extends TestCase
{
    protected $username = '114_00000001';

    protected $baseurl = '/api/sw/tfgaming';

    protected $gameCode = 'TP01';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected TfgamingswAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        // $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::TFGAMINGSW->value);
        $this->vendor->name = 'TFGaming SW';
        $this->vendor->code = 'tfgaming';
        $this->vendor->tname = 'tfgaming';
        $this->vendor->third_party_code = 'tfgaming';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'tfgaming';
        $this->vendorInfo->account_prefix = VendorIdEnum::TFGAMINGSW->value . '_';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0;
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = json_encode([
            'API_URL' => '',
            'GAME_URL' => '',
            'OPERATOR_ID' => '',
            'PUBLIC_TOKEN' => '',
            'PRIVATE_TOKEN' => '',
            'DEFAULT_LIMIT_AMOUNT' => '',
        ]);
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->account = TfgamingswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->rollingChipWallet = $walletDTO->rollingChipWallet;
        $this->wallet = $walletDTO->wallet;
    }

    protected function enableRollingChip()
    {
        $this->vendorInfo->update(['rolling_chip_status' => true]);
    }
}
