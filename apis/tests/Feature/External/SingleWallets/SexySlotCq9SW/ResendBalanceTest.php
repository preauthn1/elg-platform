<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotCq9SW;

/**
 * CQ9 resend（冪等）機制：重送同一筆 mtcode 必須回「前次處理當下」的餘額，
 * 不能回已被其他交易改動過的錢包現值。
 *
 * php artisan test tests/Feature/External/SingleWallets/SexySlotCq9SW/ResendBalanceTest.php --compact
 */
class ResendBalanceTest extends SexySlotCq9SWTestCase
{
    public function test_refund_resend_returns_balance_at_refund_time(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $mtcode = 'rel-bet-round-001:cq9';

        $this->callAction('game/bet', [
            'eventTime' => $this->eventTime(),
            'gamehall' => 'cq9',
            'gamecode' => $this->gameCode,
            'roundid' => 'round-001',
            'amount' => '100',
            'mtcode' => $mtcode,
        ])->assertJsonPath('data.balance', 900);

        $this->callAction('game/refund', ['mtcode' => $mtcode])
            ->assertJsonPath('data.balance', 1000);

        // 退款後有其他交易改動餘額，重送退款仍必須回退款當下的 1000
        $this->member->update(['balance' => '1500.0000']);

        $this->callAction('game/refund', ['mtcode' => $mtcode])
            ->assertJsonPath('data.balance', 1000);

        $this->member->refresh();
        $this->assertEquals('1500.0000', $this->member->balance);
    }

    public function test_endround_resend_returns_balance_at_settlement_time(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->callAction('game/bet', [
            'eventTime' => $this->eventTime(),
            'gamehall' => 'cq9',
            'gamecode' => $this->gameCode,
            'roundid' => 'round-002',
            'amount' => '100',
            'mtcode' => 'rel-bet-round-002:cq9',
        ])->assertJsonPath('data.balance', 900);

        $payload = [
            'gamehall' => 'cq9',
            'gamecode' => $this->gameCode,
            'roundid' => 'round-002',
            'data' => json_encode([
                ['mtcode' => 'rel-win-round-002:cq9', 'amount' => 30, 'eventtime' => $this->eventTime(), 'validbet' => 0],
                ['mtcode' => 'rel-win-round-002-1:cq9', 'amount' => 20, 'eventtime' => $this->eventTime(), 'validbet' => 0],
            ]),
            'createTime' => $this->eventTime(),
        ];

        $this->callAction('game/endround', $payload)->assertJsonPath('data.balance', 950);

        // 結算後有其他交易改動餘額，整批重送仍必須回結算當下的 950
        $this->member->update(['balance' => '1500.0000']);

        $this->callAction('game/endround', $payload)->assertJsonPath('data.balance', 950);

        $this->member->refresh();
        $this->assertEquals('1500.0000', $this->member->balance);
    }
}
