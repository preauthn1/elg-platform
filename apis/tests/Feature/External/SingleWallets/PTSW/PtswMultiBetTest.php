<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Helpers\Common;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * PTSW multi-bet 累加行為測試。
 *
 * PlayTech live casino（21 點 split / 保險、百家樂多手）允許同一個 gameRoundCode 多次 bet
 * 回調（每次帶不同 transactionCode）。BR.bet / BR.valid_bet 必須累加，不可覆寫，
 * 否則三方後台 turnover 與 SR11 報表會偏低（修復 commit 0e82fe915）。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswMultiBetTest.php --compact
 */
class PtswMultiBetTest extends PtswTestCase
{
    /**
     * 同一 gameRoundCode 連續兩次 bet（transactionCode 不同）
     * 預期：
     *   - PtswBetRecord 只有 1 筆（同 gameRoundCode 不分裂）
     *   - bet / valid_bet 累加 30 + 30 = 60
     *   - win_lose 累計為 -60（兩次下注未派彩）
     *   - status 仍為 IN_PROGRESS
     *   - 玩家錢包共扣 60
     */
    #[Group('rules/vendor-ptsw#R-12')]
    public function test_multi_bet_same_round_accumulates_bet_and_valid_bet(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $gameRoundCode = 'live_15005005080000';
        $betAmount = '30.0';

        // ── 第一次 bet ──
        $bet1Response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-1',
            'amount' => $betAmount,
            'transactionDate' => '2026-05-12T10:00:00Z',
        ]);
        $bet1Response->assertStatus(200);
        $bet1Response->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count(), '第一次 bet 後應有 1 筆 PtswBetRecord');

        // ── 第二次 bet：同 gameRoundCode、不同 transactionCode ──
        $bet2Response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-bet-2',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $gameRoundCode,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-bet-2',
            'amount' => $betAmount,
            'transactionDate' => '2026-05-12T10:00:05Z',
        ]);
        $bet2Response->assertStatus(200);
        $bet2Response->assertJsonMissing(['error']);

        $this->assertEquals(1, PtswBetRecord::count(), '同 gameRoundCode 第二次 bet 不應產生新 PtswBetRecord');

        $betRecord = PtswBetRecord::first();
        $expectedAccumulated = Common::numAdd($betAmount, $betAmount, 4);

        $this->assertEquals($gameRoundCode, $betRecord->bet_id);
        $this->assertEquals(BetRecordStatusEnum::IN_PROGRESS->value, $betRecord->status);
        $this->assertEquals(
            $expectedAccumulated,
            $betRecord->bet,
            'BR.bet 必須累加（30 + 30 = 60），而非覆寫為 30',
        );
        $this->assertEquals(
            $expectedAccumulated,
            $betRecord->valid_bet,
            'BR.valid_bet 必須累加（30 + 30 = 60），而非覆寫為 30',
        );
        $this->assertEquals(
            Common::numMul($expectedAccumulated, '-1', 4),
            $betRecord->win_lose,
            'BR.win_lose 累計兩次扣減 = -60',
        );

        // 餘額：1000 - 30 - 30 = 940
        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $expectedAccumulated, 4),
            $this->member->balance,
        );
    }
}
