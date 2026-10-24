<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Models\TotalBetRecord;
use App\Vendors\Models\UUSlotswBetRecord;
use App\Vendors\UUSlotSW\Enums\BetRecordTypeEnum;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * UUSlotSW 下注資格檢查（target/tickets#4375）
 *
 * 資格檢查改由 bet() 在鎖住會員之後以 verifyBettability() 執行；派彩與撤單刻意不檢查會員狀態，
 * 被鎖的玩家不能再下注，但先前下出去的注仍要結算、該退的錢仍要退。
 * 鎖定會員的三支與帳號停用那支是行為釘樁（改版前後一致），只有查詢順序那支會在舊寫法下變紅。
 */
class UUSlotSWBettabilityTest extends UUSlotSWTestCase
{
    private const int BET_ID = 20000016077;

    #[TestDox('會員被系統鎖定時下注被擋下，回 900416 且餘額與注單皆不變')]
    #[Group('rules/vendor-uuslotsw#R-11')]
    public function test_bet_is_rejected_when_member_is_locked(): void
    {
        $this->member->update(['balance' => '1000.0000', 'sys_lock' => 1]);

        $response = $this->postJson("{$this->baseurl}/Bet", $this->betPayload(10));

        $response->assertStatus(200)->assertJson([
            'Status' => StatusCodeEnum::OPERATOR_PLAYER_INACTIVE,
            'Description' => 'Account could not bet yet',
        ]);
        $this->assertEquals('1000.0000', $this->member->refresh()->balance);
        $this->assertEquals(0, UUSlotswBetRecord::count());
    }

    #[TestDox('會員被系統鎖定時派彩仍照常完成，不因鎖定而中斷結算')]
    #[Group('rules/vendor-uuslotsw#R-18')]
    public function test_game_result_still_settles_when_member_is_locked(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->postJson("{$this->baseurl}/Bet", $this->betPayload(10))->assertJson(['Status' => StatusCodeEnum::OPERATOR_OK]);
        $this->member->update(['sys_lock' => 1]);

        $payload = [
            'RoundType' => 0,
            'ResultType' => 0,
            'ResultId' => 20000016083,
            'BetId' => self::BET_ID,
            'RoundId' => self::BET_ID,
            'GameType' => 'Slots',
            'GameCode' => $this->game->game_type,
            'GameName' => 'Simulator',
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'BetAmount' => 10,
            'Payout' => 100,
            'WinLose' => 90,
            'TranDateTime' => '2024-08-20 12:24:38',
            'ValidBetAmount' => 10,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:38.910+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:38',
        ];
        $this->regenerateSignature('GameResult', $payload);

        $this->postJson("{$this->baseurl}/GameResult", $payload)->assertJson(['Status' => StatusCodeEnum::OPERATOR_OK]);

        // 1000 - 10/2 + 100/2
        $this->assertEquals('1045.0000', $this->member->refresh()->balance);
        $this->assertEquals(BetRecordTypeEnum::RESULT, UUSlotswBetRecord::first()->status);
        $this->assertEquals(1, TotalBetRecord::count());
    }

    #[TestDox('會員被系統鎖定時撤單仍照常退款，不因鎖定而卡住本金')]
    #[Group('rules/vendor-uuslotsw#R-21')]
    public function test_rollback_still_refunds_when_member_is_locked(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->postJson("{$this->baseurl}/Bet", $this->betPayload(10))->assertJson(['Status' => StatusCodeEnum::OPERATOR_OK]);
        $this->member->update(['sys_lock' => 1]);

        $payload = [
            'BetId' => self::BET_ID,
            'RoundId' => self::BET_ID,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => 10,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:50',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:40.602+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:50',
        ];
        $this->regenerateSignature('Rollback', $payload);

        $this->postJson("{$this->baseurl}/Rollback", $payload)->assertJson(['Status' => StatusCodeEnum::OPERATOR_OK]);

        $this->assertEquals('1000.0000', $this->member->refresh()->balance);
        $this->assertEquals(BetRecordTypeEnum::ROLLBACK, UUSlotswBetRecord::first()->status);
    }

    #[TestDox('下注資格檢查必須排在會員上鎖之後，否則快照定在等鎖前讀不到最新狀態')]
    #[Group('rules/vendor-uuslotsw#R-11')]
    public function test_bettability_is_verified_after_member_lock(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        DB::enableQueryLog();

        $this->postJson("{$this->baseurl}/Bet", $this->betPayload(10))->assertJson(['Status' => StatusCodeEnum::OPERATOR_OK]);

        $queries = collect(DB::getQueryLog())->pluck('query');
        $lockIndex = $queries->search(fn (string $q) => str_contains($q, 'from `members`') && str_contains($q, 'for update'));
        $gameIndex = $queries->search(fn (string $q) => str_contains($q, 'from `games`') && str_contains($q, '`game_type`'));

        $this->assertNotFalse($lockIndex, '下注應以 lockForUpdate 鎖住會員');
        $this->assertNotFalse($gameIndex, '資格檢查應查過 games 表');
        $this->assertGreaterThan($lockIndex, $gameIndex, '資格檢查（查 games）必須在鎖會員之後');
    }

    #[TestDox('遊戲帳號被活動系統停用（status=0）時，仍回改版前的 900404 Account not found')]
    #[Group('rules/vendor-uuslotsw#R-4')]
    public function test_disabled_account_keeps_legacy_response(): void
    {
        $this->account->update(['status' => 0]);

        $this->postJson("{$this->baseurl}/Bet", $this->betPayload(10))->assertJson([
            'Status' => StatusCodeEnum::OPERATOR_INVALID_PLAYER,
            'Description' => 'Account not found',
        ]);
    }

    private function betPayload(float $betAmount): array
    {
        $payload = [
            'BetId' => self::BET_ID,
            'RoundId' => self::BET_ID,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:37',
            'AuthToken' => '',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:37.074+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:37',
        ];
        $this->regenerateSignature('Bet', $payload);

        return $payload;
    }
}
