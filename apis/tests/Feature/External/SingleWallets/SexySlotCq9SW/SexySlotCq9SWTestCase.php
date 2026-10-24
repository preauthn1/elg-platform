<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotCq9SW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\SexySlotCq9SwAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class SexySlotCq9SWTestCase extends TestCase
{
    protected string $wtoken = [REDACTED]';

    protected string $username = 'sscq00000001';

    protected string $gameCode = '6001';

    protected string $baseUrl = '/external/api/sw/sexyslotcq9';

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Agent $agent;

    protected Member $member;

    protected SexySlotCq9SwAccount $account;

    protected Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::SEXYSLOTCQ9SW->value);
        $this->vendor->name = 'Sexy Slot CQ9 SW';
        $this->vendor->code = 'SEXYSLOTCQ9SW';
        $this->vendor->is_single_wallet = 1;
        $this->vendor->save();

        // createBasicVendorGames 建立新的 VendorInfo 並掛在測試 agent 下，取最新建立的那筆
        $this->vendorInfo = $this->vendor->vendorInfos()->latest('id')->first();

        // 停用舊的 VendorInfo，避免 findVendorInfo() 因 ORDER BY id ASC 取到錯誤的憑證
        $this->vendor->vendorInfos()
            ->where('id', '!=', $this->vendorInfo->id)
            ->update(['status' => 0]);

        $this->vendorInfo->account_prefix = 'sscq';
        $this->vendorInfo->prepared_account_number = 10;
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->currency = 'MYR';
        $this->vendorInfo->info = [
            'apiUrl' => 'https://api.cqgame.games/',
            'token' => $this->wtoken,
        ];
        $this->vendorInfo->save();

        // createBasicVendorGames 建立新 game 並設定 VendorInfoGame bettable=1，取最新建立的那筆
        $this->game = $this->vendor->games()->latest('id')->first();
        $this->game->game_type = $this->gameCode;
        $this->game->status = 1;
        $this->game->save();

        $this->account = SexySlotCq9SwAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'status' => 1,
            'member_id' => $this->member->id,
        ]);
    }

    protected function callAction(string $action, array $payload)
    {
        return $this->withHeaders(['wtoken' => $this->wtoken])
            ->postJson("{$this->baseUrl}/transaction/{$action}", $payload + ['account' => $this->username]);
    }

    protected function eventTime(): string
    {
        return now()->setTimezone(new \DateTimeZone('-04:00'))->format('Y-m-d\TH:i:s.uP');
    }
}
