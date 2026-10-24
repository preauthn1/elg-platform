<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use App\Vendors\Astar\Enums\BetRecordStatusEnum;
use App\Vendors\Models\AstarBetRecord;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * rollin 入帳以 amount 為準。棋牌桌的 win 是用戶目前餘額而非輸贏，
 * 拿 win 當輸贏會讓每局多還一次帶入金額（target/tickets#4432）。
 *
 * php artisan test tests/Feature/External/SingleWallets/Astar/AstarRollinSettleAmountTest.php --compact
 */
#[Group('ticket-4432')]
class AstarRollinSettleAmountTest extends AstarTestCase
{
    private function createRolloutRecord(string $round, string $rolloutAmount): void
    {
        AstarBetRecord::forceCreate([
            'bet_id' => $round,
            'bet' => $rolloutAmount,
            'valid_bet' => $rolloutAmount,
            'win_lose' => '-' . $rolloutAmount,
            'status' => BetRecordStatusEnum::ROLLOUT,
            'vendor_username' => $this->username,
            'bet_time' => now()->toDateTimeString(),
            'game_code' => $this->game->game_type,
            'session_token' => $this->gametoken,
            'info' => json_encode([]),
        ]);
    }

    private function rollin(string $round, array $params): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['Authorization' => $this->csToken])
            ->postJson("{$this->baseurl}/gamepool/{$this->gamehall}/table/rollin", array_merge([
                'gametoken' => $this->gametoken,
                'betpoint' => null,
                'datetime' => now()->tz('-04:00')->toRfc3339String(),
                'gamecode' => $this->game->game_type,
                'id' => $this->username,
                'mtcode' => 'rel-rollin-' . $round,
                'rake' => '0',
                'result' => null,
                'roomfee' => '0',
                'round' => $round,
                'roundnumber' => $round,
            ], $params));
    }

    /**
     * 以生產實際收到的 rollout／rollin 請求原文重跑一局：帳號、gametoken、遊戲代碼換成該局的值，
     * 錢包餘額取該局當時的實際值（GCP log 的 wallet_balance_changed）：rollout 前的餘額當起點，
     * rollout 後的餘額用來確認重跑與當時一致。
     */
    private function playRecordedRound(
        array $rollout,
        array $rollin,
        string $balanceBeforeRollout,
        string $balanceAfterRollout,
    ): void {
        $this->account->update([
            'username' => $rollout['id'],
            'vendor_username' => $rollout['id'],
            'session_token' => $rollout['gametoken'],
        ]);
        $this->game->update(['game_type' => $rollout['gamecode']]);
        $this->wallet->update(['balance' => $balanceBeforeRollout]);

        $this->withHeaders(['Authorization' => $this->csToken])
            ->postJson("{$this->baseurl}/gamepool/{$this->gamehall}/table/rollout", $rollout)
            ->assertOk()
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', $balanceAfterRollout);

        $this->withHeaders(['Authorization' => $this->csToken])
            ->postJson("{$this->baseurl}/gamepool/{$this->gamehall}/table/rollin", $rollin)
            ->assertOk()
            ->assertJsonPath('status.code', '0');
    }

    private function assertBoardCardRecord(string $round, string $bet, string $winLose): void
    {
        foreach (['astar_bet_records' => 'bet_id', 'total_bet_records' => 'reference_no'] as $table => $roundColumn) {
            $this->assertDatabaseHas($table, [
                $roundColumn => $round,
                'bet' => $bet,
                'valid_bet' => $bet,
                'win_lose' => $winLose,
            ]);
        }
    }

    #[TestDox('棋牌局 TH41041790657381527 帶入 168.60、以 128.60 離桌，錢包 168.6097 → 128.6097，注單記 40 / 40 / -40')]
    #[Group('ac-1 [自動] 棋牌局輸錢離桌時，玩家錢包只加回遊戲商給的離桌金額，帶進桌的錢不會被多還一次')]
    #[Group('rules/vendor-astar#R-16')]
    #[Group('rules/vendor-astar#R-17')]
    public function test_board_card_recorded_losing_round_credits_only_settle_amount(): void
    {
        $gametoken = [REDACTED]';

        $this->playRecordedRound(
            rollout: [
                'gametoken' => $gametoken,
                'id' => 'Y2WASTAR00004366',
                'mtcode' => 'rel-rollout-TH41041790657381527',
                'round' => 'TH41041790657381527',
                'amount' => '168.60',
                'betpoint' => null,
                'datetime' => '2026-09-29T00:49:57-04:00',
                'gamecode' => 'TH4101',
                'ip' => '0.0.0.0',
                'roundnumber' => null,
                'tabletype' => '100004',
                'tablename' => 'BOARDCARD#100004',
                'tableid' => '100004',
            ],
            rollin: [
                'gametoken' => $gametoken,
                'id' => 'Y2WASTAR00004366',
                'mtcode' => 'rel-rollin-TH41041790657381527',
                'round' => 'TH41041790657381527',
                'amount' => '128.6',
                'bet' => '-168.6',
                'betpoint' => null,
                'win' => '128.6',
                'rake' => '0',
                'datetime' => '2026-09-29T00:50:00-04:00',
                'gamecode' => 'TH4101',
                'roomfee' => '0',
                'validbet' => '-168.6',
                'roundnumber' => 'TH41041790657381527',
                'tabletype' => '100004',
                'tablename' => 'BOARDCARD#100004',
                'tableid' => '100004',
                'result' => null,
            ],
            balanceBeforeRollout: '168.6097',
            balanceAfterRollout: '0.0097',
        );

        // 修正前實際入帳 +297.20，錢包變成 297.2097
        $this->assertSame('128.6097', (string) $this->wallet->fresh()->balance);
        $this->assertBoardCardRecord('TH41041790657381527', bet: '40.0000', winLose: '-40.0000');
    }

    #[TestDox('棋牌局 TH41041790657453624 帶入 126.10、以 145.10 離桌，錢包 423.3097 → 442.3097，注單記 19 / 19 / +19')]
    #[Group('ac-2 [自動] 棋牌局贏錢離桌時，玩家錢包加回離桌金額，注單輸贏等於離桌金額減帶入金額，投注與有效投注都記輸贏的絕對值')]
    #[Group('rules/vendor-astar#R-16')]
    #[Group('rules/vendor-astar#R-17')]
    public function test_board_card_recorded_winning_round_records_net_win(): void
    {
        $gametoken = [REDACTED]';

        $this->playRecordedRound(
            rollout: [
                'gametoken' => $gametoken,
                'id' => 'Y2WASTAR00004366',
                'mtcode' => 'rel-rollout-TH41041790657453624',
                'round' => 'TH41041790657453624',
                'amount' => '126.10',
                'betpoint' => null,
                'datetime' => '2026-09-29T00:51:06-04:00',
                'gamecode' => 'TH4101',
                'ip' => '0.0.0.0',
                'roundnumber' => null,
                'tabletype' => '100004',
                'tablename' => 'BOARDCARD#100004',
                'tableid' => '100004',
            ],
            rollin: [
                'gametoken' => $gametoken,
                'id' => 'Y2WASTAR00004366',
                'mtcode' => 'rel-rollin-TH41041790657453624',
                'round' => 'TH41041790657453624',
                'amount' => '145.1',
                'bet' => '-126.1',
                'betpoint' => null,
                'win' => '145.1',
                'rake' => '0',
                'datetime' => '2026-09-29T00:51:00-04:00',
                'gamecode' => 'TH4101',
                'roomfee' => '0',
                'validbet' => '-126.1',
                'roundnumber' => 'TH41041790657453624',
                'tabletype' => '100004',
                'tablename' => 'BOARDCARD#100004',
                'tableid' => '100004',
                'result' => null,
            ],
            balanceBeforeRollout: '423.3097',
            balanceAfterRollout: '297.2097',
        );

        // 修正前實際入帳 +271.20，錢包變成 568.4097
        $this->assertSame('442.3097', (string) $this->wallet->fresh()->balance);
        $this->assertBoardCardRecord('TH41041790657453624', bet: '19.0000', winLose: '19.0000');
    }

    #[TestDox('棋牌局 TH41331790616928481 帶入 130.80、原額離桌，錢包 4607.90 → 4607.90，注單記 0 / 0 / 0')]
    #[Group('ac-3 [自動] 棋牌局平手離桌時，玩家錢包加回帶入的錢，注單投注、有效投注、輸贏都是 0')]
    #[Group('rules/vendor-astar#R-17')]
    public function test_board_card_recorded_even_round_records_zero(): void
    {
        $gametoken = [REDACTED]';

        $this->playRecordedRound(
            rollout: [
                'gametoken' => $gametoken,
                'id' => 'Y2WASTAR00004403',
                'mtcode' => 'rel-rollout-TH41331790616928481',
                'round' => 'TH41331790616928481',
                'amount' => '130.80',
                'betpoint' => null,
                'datetime' => '2026-09-28T13:35:28-04:00',
                'gamecode' => 'TH4101',
                'ip' => '0.0.0.0',
                'roundnumber' => null,
                'tabletype' => '100033',
                'tablename' => 'BOARDCARD#100033',
                'tableid' => '100033',
            ],
            rollin: [
                'gametoken' => $gametoken,
                'id' => 'Y2WASTAR00004403',
                'mtcode' => 'rel-rollin-TH41331790616928481',
                'round' => 'TH41331790616928481',
                'amount' => '130.8',
                'bet' => '-130.8',
                'betpoint' => null,
                'win' => '130.8',
                'rake' => '0',
                'datetime' => '2026-09-28T13:35:00-04:00',
                'gamecode' => 'TH4101',
                'roomfee' => '0',
                'validbet' => '-130.8',
                'roundnumber' => 'TH41331790616928481',
                'tabletype' => '100033',
                'tablename' => 'BOARDCARD#100033',
                'tableid' => '100033',
                'result' => null,
            ],
            balanceBeforeRollout: '4607.9000',
            balanceAfterRollout: '4477.1000',
        );

        // 修正前實際入帳 +261.60，錢包變成 4738.70
        $this->assertSame('4607.9000', (string) $this->wallet->fresh()->balance);
        $this->assertBoardCardRecord('TH41331790616928481', bet: '0.0000', winLose: '0.0000');
    }

    #[TestDox('真人百家樂押 40 輸掉，錢包不加回，注單照廠商有效投注 40、輸贏 -40')]
    #[Group('ac-4 [自動] 真人遊戲結算的入帳金額、有效投注與輸贏跟修正前一樣')]
    #[Group('rules/vendor-astar#R-18')]
    public function test_live_game_rollin_keeps_vendor_valid_bet_and_net_win_lose(): void
    {
        $round = 'VW1194683622';
        $this->createRolloutRecord($round, '40.00');
        $this->wallet->update(['balance' => '960.0000']);

        $this->rollin($round, [
            'amount' => '0.00',
            'bet' => '40.00',
            'validbet' => '40.00',
            'win' => '-40.00',
            'tableid' => '3006',
            'tablename' => 'BACCARAT#LV07',
            'tabletype' => '1',
        ])
            ->assertOk()
            ->assertJsonPath('data.balance', '960.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'bet' => '40.0000',
            'valid_bet' => '40.0000',
            'win_lose' => '-40.0000',
        ]);
    }

    #[TestDox('真人遊戲 amount 與 rollout + win − rake − roomfee 對不上時，照 amount 入帳並記警告 log')]
    #[Group('ac-5 [自動] 真人遊戲的結算金額對不上公式時，玩家錢包照遊戲商給的結算金額入帳，並留下一筆警告紀錄給人工核對')]
    #[Group('rules/vendor-astar#R-18')]
    public function test_live_game_rollin_logs_warning_when_settle_amount_mismatches_formula(): void
    {
        Log::spy();

        $round = 'VW1194683623';
        $this->createRolloutRecord($round, '40.00');
        $this->wallet->update(['balance' => '960.0000']);

        $this->rollin($round, [
            'amount' => '80.00',
            'bet' => '40.00',
            'validbet' => '40.00',
            'win' => '30.00',
            'tableid' => '3006',
            'tablename' => 'BACCARAT#LV07',
            'tabletype' => '1',
        ])
            ->assertOk()
            ->assertJsonPath('data.balance', '1040.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'win_lose' => '40.0000',
        ]);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_ends_with($message, 'rollin_amount_mismatch'))
            ->once();
    }
}
