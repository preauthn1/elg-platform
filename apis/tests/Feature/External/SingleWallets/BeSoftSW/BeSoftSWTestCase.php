<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\BeSoftSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\BeSoftSWAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class BeSoftSWTestCase extends TestCase
{
    protected string $baseUrl = '/external/api/sw/besoft';

    protected string $username = '224_00000001';

    protected string $gameCode = 'afa026';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected BeSoftSWAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames(
            agent: $this->agent,
            vendorId: VendorIdEnum::BESOFT->value,
            vendorAttributes: [
                'name' => 'BeSoft',
                'code' => 'besoft',
                'tname' => 'besoft',
                'third_party_code' => 'besoft',
            ],
            vendorInfoAttributes: [
                'name' => 'besoft',
                'account_prefix' => VendorIdEnum::BESOFT->value . '_',
                'prepared_account_number' => 10,
                'status' => 1,
                'rate' => 1.0,
                'currency' => 'MYR',
                'rolling_chip_status' => 0,
                'info' => json_encode([
                    'API_URL' => '',
                    'GAME_URL' => '',
                    'extension1' => 'test_agent_id',
                ]),
            ],
        );

        $this->vendorInfo = $this->vendor->vendorInfos()->first();

        $game = $this->vendor->games()->first();
        $game->game_type = $this->gameCode;
        $game->save();

        $this->account = BeSoftSWAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'admin' => 1,
            'member_id' => $this->member->id,
            'status' => 1,
        ]);
    }

    protected function makeParams(array $override = []): array
    {
        return array_merge([
            'uid' => $this->username,
            'gid' => $this->gameCode,
            'ts' => now()->getTimestampMs(),
        ], $override);
    }
}
