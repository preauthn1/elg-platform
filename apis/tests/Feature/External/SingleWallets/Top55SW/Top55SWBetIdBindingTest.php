<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Top55SW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\Models\Top55swAccount;
use App\Vendors\Models\Top55swBetRecord;
use App\Vendors\Top55\BetRecordStatusEnum;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Top55 的 historyId 以 JSON 數字送達，而 top55sw_bet_records.bet_id 是 varchar。
 * 綁整數會讓唯一索引失效退化成全表掃描，投注與派彩回調都會被拖慢。
 *
 * php artisan test tests/Feature/External/SingleWallets/Top55SW --compact
 */
class Top55SWBetIdBindingTest extends TestCase
{
    private string $baseurl = '/external/api/sw/top55/api';

    private string $userCode = 'TPGSoft00000001';

    private string $gameCode = 'fortune-snake';

    private string $clientId = 'testClientId';

    private string $clientSecret = [REDACTED]';

    private Member $member;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($agent);

        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::TOP55SW_PGSOFT->value);
        $vendor->save();

        // 對齊測試使用的 gameCode，避免 BetHelper::isMemberAbleToBet 找不到 game 而被擋下
        $vendor->games()->first()->update(['game_type' => $this->gameCode]);

        $this->vendorInfo = $vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->rolling_chip_status = 0;
        $this->vendorInfo->info = [
            'clientId' => $this->clientId,
            'clientSecret' => $this->clientSecret,
        ];
        $this->vendorInfo->save();

        Top55swAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->userCode,
            'member_id' => $this->member->id,
            'status' => 1,
            'admin' => 1,
        ]);

        $this->member->update(['balance' => '100.0000']);
    }

    private function transactionPayload(int $historyId, float $amount): array
    {
        return [
            'userCode' => $this->userCode,
            'vendorCode' => 'slot-pgsoft',
            'gameCode' => $this->gameCode,
            'historyId' => $historyId,
            'roundId' => (string) $historyId,
            'gameType' => 2,
            'transactionCode' => uniqid('tc', true),
            'isFinished' => $amount > 0,
            'isCanceled' => false,
            'amount' => $amount,
            'detail' => '{}',
            'createdAt' => '2026-10-12T09:12:58.9483436Z',
        ];
    }

    private function postTransaction(array $payload): \Illuminate\Testing\TestResponse
    {
        $authorization = 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);

        return $this->postJson("{$this->baseurl}/transaction", $payload, ['Authorization' => $authorization]);
    }

    /**
     * @return array<int, mixed> 對 top55sw_bet_records 以 bet_id 查詢時帶入的所有綁定值
     */
    private function captureBetIdBindings(callable $act): array
    {
        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (str_contains($query->sql, 'top55sw_bet_records') && str_contains($query->sql, 'bet_id')) {
                $bindings = array_merge($bindings, $query->bindings);
            }
        });

        $act();

        return $bindings;
    }

    #[TestDox('投注：historyId 以 JSON 數字送達時，bet_id 仍必須以字串綁定（唯一索引可用性）')]
    #[Group('rules/vendor-top55#R-1')]
    #[Group('rules/vendor-top55#R-5')]
    public function test_bet_binds_bet_id_as_string(): void
    {
        $historyId = 4497078117;

        $bindings = $this->captureBetIdBindings(function () use ($historyId) {
            $this->postTransaction($this->transactionPayload($historyId, -0.6))
                ->assertStatus(200)
                ->assertJson(['success' => true, 'errorCode' => 0]);
        });

        $this->assertDatabaseHas('top55sw_bet_records', [
            'bet_id' => (string) $historyId,
            'status' => BetRecordStatusEnum::BET->value,
        ]);
        $this->assertContains((string) $historyId, $bindings, 'bet_id 必須以字串綁定');
        $this->assertNotContains($historyId, $bindings, 'bet_id 綁成 int 會讓唯一索引失效');
    }

    #[TestDox('派彩：historyId 以 JSON 數字送達時，查注單的 bet_id 仍必須以字串綁定')]
    #[Group('rules/vendor-top55#R-7')]
    public function test_settle_binds_bet_id_as_string(): void
    {
        $historyId = 4497081451;
        $this->postTransaction($this->transactionPayload($historyId, -0.6))->assertJson(['success' => true]);

        $bindings = $this->captureBetIdBindings(function () use ($historyId) {
            $this->postTransaction($this->transactionPayload($historyId, 0.72))
                ->assertStatus(200)
                ->assertJson(['success' => true, 'errorCode' => 0]);
        });

        $this->assertSame(
            BetRecordStatusEnum::SETTLEMENT->value,
            Top55swBetRecord::where('bet_id', (string) $historyId)->value('status')
        );
        $this->assertContains((string) $historyId, $bindings, 'bet_id 必須以字串綁定');
        $this->assertNotContains($historyId, $bindings, 'bet_id 綁成 int 會讓唯一索引失效');
    }
}
