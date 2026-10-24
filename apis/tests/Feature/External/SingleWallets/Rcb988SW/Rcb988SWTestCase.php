<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\Rcb988Account;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class Rcb988SWTestCase extends TestCase
{
    protected string $username = 'vprcb00000019';

    protected string $baseUrl = '/external/api/sw/rcb988';

    protected string $clientSecret = [REDACTED]';

    protected string $gameCode = 'HRB-LIVE-001';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected Rcb988Account $account;

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

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::RCB988->value);

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = json_encode([
            'AUTH_URL' => 'https://api.rcb988.test',
            'CLIENT_ID' => 'test-client-id',
            'CLIENT_SECRET' => $this->clientSecret,
            'CLIENT_GAME_PLATFORM' => 'RCB',
            'CLIENT_GAME_TYPE' => 'LIVE',
            'CLIENT_GAME_CODE' => 'RCB001',
            'CLIENT_CLASS' => 'A',
            'CLIENT_MAJOR_MIN_BET' => '1',
            'CLIENT_MINOR_MIN_BET' => '1',
            'CLIENT_MAJOR_MAX_BET' => '10000',
            'CLIENT_MINOR_MAX_BET' => '10000',
            'CLIENT_MAJOR_MAX_BET_SUM_PER_HORSE' => '100000',
            'CLIENT_MINOR_MAX_BET_SUM_PER_HORSE' => '100000',
            'CURRENCY' => 'MYR',
        ]);
        $this->vendorInfo->save();

        $this->account = Rcb988Account::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'vendor_username' => $this->username,
            'member_id' => $this->member->id,
            'admin' => 1,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;
        $this->rollingChipWallet = $walletDto->rollingChipWallet;
    }

    protected function buildMessage(string $action, array $extra = []): string
    {
        return json_encode(array_merge(['action' => $action], $extra));
    }

    protected function postEntrance(array $params): \Illuminate\Testing\TestResponse
    {
        return $this->post($this->baseUrl, $params);
    }
}
