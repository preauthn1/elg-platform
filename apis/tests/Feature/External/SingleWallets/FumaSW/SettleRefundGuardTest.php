<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\FumaSW;

use App\Enums\BetRecordStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\FumaSW\Datas\SettleInfoData;
use App\Vendors\FumaSW\Enums\BetTypeEnum;
use App\Vendors\FumaSW\Libs\Settle;
use App\Vendors\Models\FumaswAccount;
use App\Vendors\Models\FumaswBetRecord;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Exception;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/External/SingleWallets/FumaSW/SettleRefundGuardTest.php --compact
 *
 * 已被 bet-record-recovery:refund-unsettled 退款（status=2）的下注列，若三方遲到送 action 3，
 * 必須拒絕結算；否則會把 status 改回 1 並派彩，等同雙重入帳。
 */
class SettleRefundGuardTest extends TestCase
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

    private function seedPlaceBet(int $status): FumaswBetRecord
    {
        return FumaswBetRecord::create([
            'bet_id' => '998090523480201195',
            'round_id' => '27200617',
            'username' => $this->account->username,
            'game_code' => '7',
            'type' => BetTypeEnum::BET,
            'status' => $status,
            'bet' => '1.0000',
            'valid_bet' => '1.0000',
            'win_lose' => '-1.0000',
            'rolling_chips' => '0.0000',
            'infos' => json_encode(['action' => 2]),
            'bet_time' => '2026-09-05 15:48:02',
            'payout_time' => '2026-09-05 15:48:02',
        ]);
    }

    private function settleDto(float $win): SettleInfoData
    {
        return new SettleInfoData([
            'action' => 3,
            'ts' => 1788623300000,
            'transferId' => '998090523480201999',
            'uid' => $this->account->username,
            'roundId' => 27200617,
            'freewin' => 0,
            'lastfree' => 0,
            'gType' => 7,
            'des' => 'Win',
            'currency' => 'MYR',
            'bet' => 1.0,
            'win' => $win,
            'ipAddress' => '0.0.0.0',
        ]);
    }

    #[Group('rules/vendor-fumasw#R-12')]
    public function test_settle_rejects_place_bet_that_was_already_refunded(): void
    {
        $placeBet = $this->seedPlaceBet(BetRecordStatusEnum::REFUND);

        $settle = new Settle;
        $settle->setSettings($this->settleDto(3.0), $this->vendorInfo, $this->account);

        $thrown = null;
        try {
            $settle->exec();
        } catch (Exception $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '已退款的下注列再收到 action 3 必須拋例外');
        $this->assertStringContainsString('refunded', $thrown->getMessage());
        $this->assertSame('100.0000', (string) $this->member->refresh()->balance, '錢包不得被派彩');
        $this->assertSame(BetRecordStatusEnum::REFUND, (int) $placeBet->refresh()->status, 'status 不得被改回 1');
        $this->assertSame(
            0,
            FumaswBetRecord::where('round_id', $placeBet->round_id)->where('type', BetTypeEnum::SETTLE)->count(),
            '不得建立派彩列'
        );
    }

    #[Group('rules/vendor-fumasw#R-14')]
    public function test_settle_still_pays_out_unsettled_place_bet(): void
    {
        $placeBet = $this->seedPlaceBet(BetRecordStatusEnum::PENDING);

        $settle = new Settle;
        $settle->setSettings($this->settleDto(3.0), $this->vendorInfo, $this->account);
        $balance = $settle->exec();

        $this->assertSame(0, Common::numCompare($balance, '103', 4));
        $this->assertSame('103.0000', (string) $this->member->refresh()->balance);
        $this->assertSame(1, (int) $placeBet->refresh()->status);
        $this->assertSame(
            1,
            FumaswBetRecord::where('round_id', $placeBet->round_id)->where('type', BetTypeEnum::SETTLE)->count()
        );
    }
}
