<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\WalletService;
use App\Vendors\Models\SexyslotswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class SexySlotSWTestCase extends TestCase
{
    protected string $clientApiKey = [REDACTED]';

    protected string $clientAccessToken = [REDACTED]';

    protected string $username = 'sexyslotsw00000001';

    protected string $gameCode = 'SXSLT_TEST_GAME_001';

    protected string $baseUrl = '/external/api/sw/sexyslotsw';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected SexyslotswAccount $account;

    protected Member|MemberWallet $wallet;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::SEXYSLOTSW->value);
        $this->vendor->name = 'Sexy Slot SW';
        $this->vendor->code = 'SEXYSLOTSW';
        $this->vendor->is_single_wallet = 1;
        $this->vendor->save();

        // createBasicVendorGames 建立新的 VendorInfo 並掛在測試 agent 下，取最新建立的那筆
        $this->vendorInfo = $this->vendor->vendorInfos()->latest('id')->first();

        // 停用舊的 VendorInfo，避免 findVendorInfo() 因 ORDER BY id ASC 取到錯誤的憑證
        $this->vendor->vendorInfos()
            ->where('id', '!=', $this->vendorInfo->id)
            ->update(['status' => 0]);

        $this->vendorInfo->account_prefix = 'sexyslotsw';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = 'MYR';
        $this->vendorInfo->info = [
            'API_URL' => 'https://example.invalid/api/',
            'CLIENT_ID' => '11533',
            'USERNAME' => 'user@example.invalid',
            'PASSWORD' => 'testpass',
            'CLIENT_SECRET' => 'testSecret',
            'CLIENT_API_KEY' => $this->clientApiKey,
            'CLIENT_ACCESS_TOKEN' => $this->clientAccessToken,
        ];
        $this->vendorInfo->save();

        // createBasicVendorGames 建立新 game 並設定 VendorInfoGame bettable=1，取最新建立的那筆
        $this->game = $this->vendor->games()->latest('id')->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->account = SexyslotswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'session_token' => '17E259F53030A5B6C62FF00765FB47C4',
            'status' => 1,
            'member_id' => $this->member->id,
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo);
        $this->wallet = $walletDto->wallet;

    }

    protected function validHashkey(): string
    {
        return md5($this->clientApiKey . $this->clientAccessToken);
    }

    protected function playerPayload(array $overrides = []): array
    {
        return array_merge([
            'access_token' => $this->clientAccessToken,
            'hashkey' => $this->validHashkey(),
            'type' => 'playerdetailsrequest',
            'datesent' => now()->toDateTimeString(),
            'clientid' => 11533,
            'playerdetailsrequest' => [
                'client_player_id' => $this->username,
                'token' => '17E259F53030A5B6C62FF00765FB47C4',
                'gamelaunch' => true,
                'player_username' => $this->username,
                'refreshtoken' => false,
            ],
        ], $overrides);
    }

    protected function fundPayload(string $transactionType, string $transactionId, string $roundId, float $amount, array $overrides = []): array
    {
        return array_merge([
            'access_token' => $this->clientAccessToken,
            'hashkey' => $this->validHashkey(),
            'type' => 'fundtransfer',
            'datesent' => now()->toDateTimeString(),
            'gamedetails' => [
                'gameid' => $this->gameCode,
                'gamename' => 'Sexy Bunny',
                'provider_name' => 'SexySlot',
            ],
            'fundtransferrequest' => [
                'playerinfo' => [
                    'username' => $this->username,
                    'client_player_id' => $this->username,
                    'token' => '17E259F53030A5B6C62FF00765FB47C4',
                ],
                'fundinfo' => [
                    'gamesessionid' => '',
                    'transactiontype' => $transactionType,
                    'transactionId' => $transactionId,
                    'roundId' => $roundId,
                    'rollback' => false,
                    'currencycode' => 'MYR',
                    'amount' => $amount,
                    'endround' => false,
                ],
            ],
        ], $overrides);
    }
}
