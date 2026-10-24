<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Vendors\Models\Rcb988BetRecord;
use App\Vendors\Rcb988\Enums\BetRecordStatusEnum;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * RCB988 下注資格檢查（target/tickets#4375）
 *
 * 資格檢查由 bet() 在鎖住會員之後以 verifyBettability() 執行，不符收斂為 ACCOUNT_LOCKED；
 * settle／cancel 刻意不檢查會員狀態——被鎖的玩家不能再下注，但先前下出去的注仍要結算、該退的錢仍要退。
 * 改版前 settle／cancel 也不擋、鎖序也已正確（2c219dbcb），故那三支與商戶停用那支是行為釘樁；
 * 只有 bettable=0 那支會在舊寫法下變紅——舊寫法沒掛介面，走的是 game.status 那組檢查、不看 bettable
 * （下注被擋那支由既有 Rcb988SWBetTest::test_bet_member_locked 覆蓋）。
 */
class Rcb988SWBettabilityTest extends Rcb988SWTestCase
{
    private string $platformTxId = 'TEST-BETTABILITY-001';

    #[TestDox('會員被系統鎖定時結算仍照常派彩，不因鎖定而中斷')]
    #[Group('rules/vendor-rcb988#R-21')]
    public function test_settle_still_pays_when_member_is_locked(): void
    {
        $this->member->update(['balance' => 100]);
        $this->postEntrance($this->betParams())->assertJsonFragment(['status' => ErrorCodeEnum::SUCCESS->value]);
        $this->member->update(['sys_lock' => 1]);

        $this->postEntrance($this->txnParams('settle', ['winAmount' => 50, 'txTime' => '2026-05-04T11:50:00.000+08:00']))
            ->assertJsonFragment(['status' => ErrorCodeEnum::SUCCESS->value]);

        // 100 - 10 + 50
        $this->assertEquals('140.0000', $this->member->refresh()->balance);
        $this->assertEquals(BetRecordStatusEnum::SETTLE->value, Rcb988BetRecord::where('bet_id', $this->platformTxId)->value('status'));
    }

    #[TestDox('會員被系統鎖定時取消投注仍照常退款，不因鎖定而卡住本金')]
    #[Group('rules/vendor-rcb988#R-26')]
    public function test_cancel_still_refunds_when_member_is_locked(): void
    {
        $this->member->update(['balance' => 100]);
        $this->postEntrance($this->betParams())->assertJsonFragment(['status' => ErrorCodeEnum::SUCCESS->value]);
        $this->member->update(['sys_lock' => 1]);

        $this->postEntrance($this->txnParams('cancelBet', ['txTime' => '2026-05-04T11:50:00.000+08:00']))
            ->assertJsonFragment(['status' => ErrorCodeEnum::SUCCESS->value]);

        $this->assertEquals('100.0000', $this->member->refresh()->balance);
        $this->assertEquals(BetRecordStatusEnum::CANCEL->value, Rcb988BetRecord::where('bet_id', $this->platformTxId)->value('status'));
    }

    #[TestDox('遊戲被標為不可投注（vendor_info_games.bettable=0）時下注被擋，回 ACCOUNT_LOCKED')]
    #[Group('rules/vendor-rcb988#R-14')]
    public function test_bet_is_rejected_when_game_is_not_bettable(): void
    {
        $this->member->update(['balance' => 100]);
        $this->vendorInfo->vendorInfoGames()->where('game_id', $this->game->id)->update(['bettable' => 0]);

        $this->postEntrance($this->betParams())->assertJsonFragment(['status' => ErrorCodeEnum::ACCOUNT_LOCKED->value]);

        $this->assertEquals('100.0000', $this->member->refresh()->balance);
        $this->assertEquals(0, Rcb988BetRecord::count());
    }

    #[TestDox('下注資格檢查必須排在會員上鎖之後，否則快照定在等鎖前讀不到最新狀態')]
    #[Group('rules/vendor-rcb988#R-13')]
    public function test_bettability_is_verified_after_member_lock(): void
    {
        $this->member->update(['balance' => 100]);
        DB::enableQueryLog();

        $this->postEntrance($this->betParams())->assertJsonFragment(['status' => ErrorCodeEnum::SUCCESS->value]);

        $queries = collect(DB::getQueryLog())->pluck('query');
        $lockIndex = $queries->search(fn (string $q) => str_contains($q, 'from `members`') && str_contains($q, 'for update'));
        $gameIndex = $queries->search(fn (string $q) => str_contains($q, 'from `games`') && str_contains($q, '`game_type`'));

        $this->assertNotFalse($lockIndex, '下注應以 lockForUpdate 鎖住會員');
        $this->assertNotFalse($gameIndex, '資格檢查應查過 games 表');
        $this->assertGreaterThan($lockIndex, $gameIndex, '資格檢查（查 games）必須在鎖會員之後');
    }

    #[TestDox('商戶停用（vendor_info.status=0）時仍回改版前的 FAIL，不因改用共用組裝而放行')]
    #[Group('rules/vendor-rcb988#R-5')]
    public function test_disabled_vendor_info_keeps_legacy_response(): void
    {
        $this->vendorInfo->update(['status' => 0]);

        $this->postEntrance($this->betParams())->assertJsonFragment([
            'status' => ErrorCodeEnum::FAIL->value,
            'desc' => 'Can not find corresponding vendor info',
        ]);
    }

    private function betParams(): array
    {
        return $this->txnParams('bet', ['betAmount' => 10, 'betTime' => '2026-05-04T11:49:47.000+08:00']);
    }

    private function txnParams(string $action, array $extra): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage($action, [
                'txns' => [array_merge([
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $this->platformTxId,
                ], $extra)],
            ]),
        ];
    }
}
