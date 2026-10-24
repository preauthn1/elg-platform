<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\FumaSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\FumaSW\Datas\SettleInfoData;
use App\Vendors\FumaSW\Enums\ErrorCodeEnum;
use App\Vendors\FumaSW\Libs\Settle;
use App\Vendors\Models\FumaswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Exception;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/External/SingleWallets/FumaSW/SettleRoundNotFoundTest.php --compact
 *
 * 廠商 2026-09 API 文件更新：Settle（action 3）依 roundId 查無對應下注記錄時，
 * 改定義為專屬錯誤碼 9012（回合不存在），取代原本的通用 9999。
 */
class SettleRoundNotFoundTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private FumaswAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::FUMASW->value);
        $this->vendor->games()->first()->update(['game_type' => '7']);
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'FUMATEST_';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = ['DC' => 'T01', 'KEY' => 'k', 'IV' => 'i', 'API_URL' => '', 'PARENT' => 'p'];
        $this->vendorInfo->save();

        $this->account = FumaswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => 'FUMATEST_00000001',
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->member->update(['balance' => '100.0000']);
    }

    private function settleDto(): SettleInfoData
    {
        return new SettleInfoData([
            'action' => 3,
            'ts' => 1788623300000,
            'transferId' => '998090523480201999',
            'uid' => $this->account->username,
            // 沒有對應 BET 記錄的 roundId
            'roundId' => 99999999,
            'freewin' => 0,
            'lastfree' => 0,
            'gType' => 7,
            'des' => 'Win',
            'currency' => 'MYR',
            'bet' => 1.0,
            'win' => 3.0,
            'ipAddress' => '0.0.0.0',
        ]);
    }

    public function test_settle_throws_round_not_found_when_no_matching_place_bet(): void
    {
        $settle = new Settle;
        $settle->setSettings($this->settleDto(), $this->vendorInfo, $this->account);

        $thrown = null;
        try {
            $settle->exec();
        } catch (Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '查無對應下注記錄時必須拋例外');
        $this->assertSame(ErrorCodeEnum::ROUND_NOT_FOUND, $thrown->getCode());
        $this->assertSame('100.0000', (string) $this->member->refresh()->balance, '錢包不得被派彩');
    }
}
