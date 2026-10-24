<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\AstarAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class AstarTestCase extends TestCase
{
    protected $baseurl = '/external/api/sw/astar';

    protected $gamehall = 'VW1';

    protected $csToken = [REDACTED]';

    protected $username = 'v101i2a00009857';

    protected $gametoken = [REDACTED]';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected AstarAccount $account;

    protected Member|MemberWallet $wallet;

    protected ?MemberWallet $rollingChipWallet = null;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->withoutMiddleware();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::ASTAR->value);
        $this->vendor->name = 'ASTAR';
        $this->vendor->code = 'astar';
        $this->vendor->tname = 'astar';
        $this->vendor->third_party_code = 'ASTAR';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'astar';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->currency = 'MYR';
        $this->vendorInfo->info = json_encode([
            'GAME_URL' => 'http://example.invalid/api/sw/astar',
            'CS_TOKEN' => $this->csToken,
            'GAMEHALL' => $this->gamehall,
            'CURRENCY' => 'MYR',
        ]);
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = 'VW101';
        $this->game->status = 1;
        $this->game->save();

        $this->account = AstarAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'vendor_username' => $this->username,
            'vendor_uid' => $this->username,
            'session_token' => $this->gametoken,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);

        // Add wallet balance
        $walletDatas = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDatas->wallet;
        $this->rollingChipWallet = $walletDatas->rollingChipWallet;

        $this->wallet->update(['balance' => 1000]);
    }
}
