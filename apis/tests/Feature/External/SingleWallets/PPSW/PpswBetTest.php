<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\Models\PpswDealRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;

/**
 * 驗證 PPSW bet 端點行為：
 *   正常投注、餘額不足、遊戲不存在、Hash 驗證失敗、缺少參數、冪等性。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswBetTest.php --compact
 */
class PpswBetTest extends PpswTestCase
{
    /**
     * 正常投注：扣款、建立 BetRecord（status=BET）與 DealRecord，不建立 TotalBetRecord。
     */
    public function test_bet_deducts_balance_and_creates_records(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $roundId = 'bet-round-001';
        $betAmount = '50.0';
        $reference = 'bet-ref-001';

        $payload = $this->withHash([
            'amount' => $betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $reference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bet.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $this->assertEquals(1, PpswBetRecord::count());
        $this->assertEquals(1, PpswDealRecord::count());
        $this->assertEquals(0, TotalBetRecord::count(), 'endRound 前不應建立 TotalBetRecord');

        $betRecord = PpswBetRecord::first();
        $this->assertEquals($roundId, $betRecord->bet_id);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->bet);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->valid_bet);
        $this->assertEquals(Common::numMul($betAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals(BetRecordStatusEnum::BET, $betRecord->status);

        $dealRecord = PpswDealRecord::first();
        $this->assertEquals('bet', $dealRecord->action);
        $this->assertEquals($reference, $dealRecord->reference_no);
        $this->assertEquals(1, $dealRecord->status);

        $this->member->refresh();
        $expectedBalance = Common::numSub($walletBalance, Common::numAdd('0', $betAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    /**
     * 餘額不足：回傳 error:1，不建立任何 BetRecord。
     */
    public function test_bet_insufficient_balance_returns_error_1(): void
    {
        $this->member->update(['balance' => '10.0000']);

        $payload = $this->withHash([
            'amount' => '50.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bet-ref-insuf',
            'roundDetails' => 'spin',
            'roundId' => 'round-insuf',
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bet.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 1]);

        $this->assertEquals(0, PpswBetRecord::count());
    }

    /**
     * 遊戲不存在：回傳 error:8，不建立任何 BetRecord。
     */
    public function test_bet_game_not_found_returns_error_8(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload = $this->withHash([
            'amount' => '30.0',
            'gameId' => 'non-existent-game-xyz',
            'providerId' => $this->providerId,
            'reference' => 'bet-ref-no-game',
            'roundDetails' => 'spin',
            'roundId' => 'round-no-game',
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bet.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 8]);

        $this->assertEquals(0, PpswBetRecord::count());
    }

    /**
     * Hash 驗證失敗：回傳 error:5。
     */
    public function test_bet_invalid_hash_returns_error_5(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $payload = [
            'amount' => '30.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bet-ref-hash',
            'roundDetails' => 'spin',
            'roundId' => 'round-hash',
            'timestamp' => '1775602502471',
            'userId' => $this->username,
            'hash' => 'invalidhash00000000000000000000000',
        ];

        $this->postJson("{$this->baseurl}/bet.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 5]);
    }

    /**
     * 缺少必要參數（roundId）：回傳 error:7。
     */
    public function test_bet_missing_required_params_returns_error_7(): void
    {
        $params = [
            'amount' => '30.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bet-ref-missing',
            'roundDetails' => 'spin',
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ];
        $params['hash'] = $this->calculateHash($params);

        $this->postJson("{$this->baseurl}/bet.html", $params)
            ->assertStatus(200)
            ->assertJson(['error' => 7]);
    }

    /**
     * 冪等性：相同 reference 兩次 bet，BetRecord 只有 1 筆，餘額只扣一次。
     */
    public function test_bet_duplicate_reference_is_idempotent(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '30.0';
        $payload = $this->withHash([
            'amount' => $betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bet-ref-idem',
            'roundDetails' => 'spin',
            'roundId' => 'round-idem',
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bet.html", $payload)
            ->assertStatus(200)->assertJson(['error' => 0]);

        $this->postJson("{$this->baseurl}/bet.html", $payload)
            ->assertStatus(200)->assertJson(['error' => 0]);

        $this->assertEquals(1, PpswBetRecord::count(), '相同 reference 只應產生一筆 BetRecord');
        $this->assertEquals(1, PpswDealRecord::count(), '相同 reference 只應產生一筆 DealRecord');

        $this->member->refresh();
        $expectedBalance = Common::numSub($walletBalance, Common::numAdd('0', $betAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance, '餘額只應扣一次');
    }
}
