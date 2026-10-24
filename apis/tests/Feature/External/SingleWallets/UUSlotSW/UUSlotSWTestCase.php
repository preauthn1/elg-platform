<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\UUSlotswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

class UUSlotSWTestCase extends TestCase
{
    protected string $merchantCode = 'uustpowerMYR';

    protected string $acctId = '82_UUSSW00000007';

    protected string $securityKey = 'testtest';

    protected string $baseurl = '/external/api/sw/uuslot';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected UUSlotswAccount $account;

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

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::UUSLOTSW->value);
        $this->vendor->name = 'UU Slot SW';
        $this->vendor->code = 'uuslotsw';
        $this->vendor->tname = 'uuslotsw';
        $this->vendor->third_party_code = 'uuslotsw';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'uuslotsw';
        $this->vendorInfo->account_prefix = VendorIdEnum::UUSLOTSW->value . '_UUSSW';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 2.0;
        $this->vendorInfo->rolling_chip_status = 1;
        $this->vendorInfo->info = json_encode([
            'API_URL' => '',
            'GAME_URL' => '',
            'Merchant_Code' => $this->merchantCode,
            'CURRENCY' => 'MYR',
            'Security_key' => $this->securityKey,
        ]);
        $this->vendorInfo->save();

        $this->game = $this->vendor->games()->first();
        $this->game->game_type = Str::random(10);
        $this->game->status = 1;
        $this->game->save();

        $this->account = UUSlotswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->acctId,
            'admin' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDTO = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->rollingChipWallet = $walletDTO->rollingChipWallet;
        $this->wallet = $walletDTO->wallet;
    }

    protected function regenerateSignature(string $functionName, array &$postData)
    {
        $requestDateTime = $postData['RequestDateTime'] ?? '';
        $operatorId = $postData['OperatorId'];
        $playerId = $postData['PlayerId'];

        $uniqueId = '';
        switch ($functionName) {
            case 'Bet':
                $uniqueId = $postData['BetId'] ?? '';
                break;
            case 'GameResult':
                $uniqueId = $postData['ResultId'] ?? '';
                break;

            case 'Rollback':
                $uniqueId = $postData['BetId'] ?? '';
                break;

            case 'CashBonus':
            case 'Jackpot':
                $uniqueId = $postData['TranId'] ?? '';
                break;
        }

        $secKey = $this->securityKey ?? '';
        if (empty($secKey)) {
            return false;
        }

        $newSignature = md5($functionName . $uniqueId . $requestDateTime . $operatorId . $secKey . $playerId);
        $postData['Signature'] = $newSignature;
    }
}
