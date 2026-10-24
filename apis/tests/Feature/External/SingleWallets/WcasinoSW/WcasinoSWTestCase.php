<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WcasinoSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\WcasinoswAccount;
use App\Vendors\Models\WcasinoswBetRecord;
use App\Vendors\Models\WcasinoswNotifyRecord;
use App\Vendors\Wcasino\Enums\NotifyTypeEnum;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class WcasinoSWTestCase extends TestCase
{
    protected string $username = 'TESTWCASW00000001';

    protected string $baseUrl = '/external/api/sw/wcasino/api';

    protected string $gameCode = 'TP01';

    protected string $clientSecret = [REDACTED]';

    protected string $appId = 'test_app_id';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected WcasinoswAccount $account;

    protected Member|MemberWallet $wallet;

    protected MemberWallet $rollingChipWallet;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::WCASINO->value);
        $this->vendor->name = 'Wcasino SW';
        $this->vendor->code = 'wcasino';
        $this->vendor->tname = 'wcasino';
        $this->vendor->third_party_code = 'wcasino';
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->name = 'wcasino';
        $this->vendorInfo->account_prefix = VendorIdEnum::WCASINO->value . '_';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = json_encode([
            'AUTH_URL' => '',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => $this->clientSecret,
            'CLIENT_QID' => 'test_qid',
            'CLIENT_CLASS' => 'test_class',
            'CURRENCY' => 'MYR',
        ]);
        $this->vendorInfo->save();

        $game = $this->vendor->games()->first();
        $game->game_type = $this->gameCode;
        $game->status = 1;
        $game->save();

        $this->account = WcasinoswAccount::create([
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

    protected function enableRollingChip(): void
    {
        $this->vendorInfo->update(['rolling_chip_status' => true]);
    }

    /**
     * 產生 Wcasino 簽名，模擬 WcasinoSWController::verifySign 的計算邏輯
     */
    protected function generateSign(array $params): string
    {
        unset($params['sign'], $params['bets']);
        $params = array_change_key_case($params, CASE_LOWER);
        $params = array_filter($params);
        ksort($params);
        $dataString = urldecode(http_build_query($params));

        return md5($dataString . '&key=' . $this->clientSecret);
    }

    protected function makeBetParams(array $overrides = []): array
    {
        $params = array_merge([
            'appid' => $this->appId,
            'username' => $this->username,
            'amount' => -100,
            'notifyid' => 'notify_' . uniqid(),
            'type' => NotifyTypeEnum::BET->value,
            'serialnumber' => 'sn_' . uniqid(),
            'bets' => json_encode(['gamecode' => $this->gameCode, 'createtime' => time()]),
        ], $overrides);
        $params['sign'] = $this->generateSign($params);

        return $params;
    }

    protected function makeSettleParams(string $serialNumber, array $overrides = []): array
    {
        $params = array_merge([
            'appid' => $this->appId,
            'username' => $this->username,
            'amount' => 80,
            'notifyid' => 'notify_' . uniqid(),
            'type' => NotifyTypeEnum::SETTLE->value,
            'serialnumber' => $serialNumber,
            'bets' => json_encode([
                'gamecode' => $this->gameCode,
                'commamount' => 100,
                'settletime' => time(),
            ]),
        ], $overrides);
        $params['sign'] = $this->generateSign($params);

        return $params;
    }

    protected function makeRollbackParams(string $serialNumber, int $type, array $overrides = []): array
    {
        $params = array_merge([
            'appid' => $this->appId,
            'username' => $this->username,
            'amount' => 100,
            'notifyid' => 'notify_rb_' . uniqid(),
            'type' => $type,
            'serialnumber' => $serialNumber,
        ], $overrides);
        $params['sign'] = $this->generateSign($params);

        return $params;
    }

    protected function createBetRecord(
        string $serialNumber,
        float $bet = 100,
        int $status = 0,
        float $rollingChips = 0,
    ): WcasinoswBetRecord {
        return WcasinoswBetRecord::create([
            'bet_id' => $serialNumber,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => $status,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => -$bet,
            'rolling_chips' => $rollingChips,
            'bet_time' => now()->toDateTimeString(),
            'payout_time' => now()->toDateTimeString(),
        ]);
    }

    protected function createNotifyRecord(
        string $betId,
        int $type,
        float $amount = 100,
        bool $isDone = true,
        bool $isRollback = false,
    ): WcasinoswNotifyRecord {
        return WcasinoswNotifyRecord::create([
            'bet_id' => $betId,
            'notify_id' => 'notify_' . uniqid(),
            'type' => $type,
            'amount' => $amount,
            'is_done' => $isDone,
            'is_rollback' => $isRollback,
            'infos' => '{}',
        ]);
    }
}
