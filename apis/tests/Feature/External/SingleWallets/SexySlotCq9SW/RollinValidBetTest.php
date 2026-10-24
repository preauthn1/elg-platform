<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotCq9SW;

use App\Vendors\Models\SexySlotCq9SwBetRecord;

/**
 * api.md 第 83 行：Slot/Arcade/Fish 廠商固定回傳 validbet=0，有效投注須改用 bet 金額；
 * Table/Live 等其他遊戲類型才直接採用 validbet。
 *
 * php artisan test tests/Feature/External/SingleWallets/SexySlotCq9SW/RollinValidBetTest.php --compact
 */
class RollinValidBetTest extends SexySlotCq9SWTestCase
{
    private function rollout(string $roundId, string $mtcode): void
    {
        $this->callAction('game/rollout', [
            'eventTime' => $this->eventTime(),
            'gamehall' => 'cq9',
            'gamecode' => $this->gameCode,
            'roundid' => $roundId,
            'amount' => '100',
            'mtcode' => $mtcode,
        ])->assertOk();
    }

    private function rollinPayload(string $roundId, string $mtcode, string $gametype): array
    {
        return [
            'eventTime' => $this->eventTime(),
            'gamehall' => 'cq9',
            'gamecode' => $this->gameCode,
            'roundid' => $roundId,
            'validbet' => '0',
            'bet' => '100',
            'win' => '20',
            'roomfee' => '0',
            'amount' => '120',
            'mtcode' => $mtcode,
            'createTime' => $this->eventTime(),
            'rake' => '0',
            'gametype' => $gametype,
            'tableid' => '',
        ];
    }

    public function test_slot_gametype_uses_bet_as_valid_bet_when_vendor_sends_zero(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->rollout('round-slot-001', 'rollout-slot-001:cq9');
        $this->callAction('game/rollin', $this->rollinPayload('round-slot-001', 'rollin-slot-001:cq9', 'slot'))
            ->assertOk();

        $betRecord = SexySlotCq9SwBetRecord::where('bet_id', 'round-slot-001')->first();

        $this->assertEquals('100.0000', $betRecord->bet);
        $this->assertEquals('100.0000', $betRecord->valid_bet);
    }

    public function test_table_gametype_uses_vendor_validbet_directly(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->rollout('round-table-001', 'rollout-table-001:cq9');

        $payload = $this->rollinPayload('round-table-001', 'rollin-table-001:cq9', 'table');
        $payload['validbet'] = '80';

        $this->callAction('game/rollin', $payload)->assertOk();

        $betRecord = SexySlotCq9SwBetRecord::where('bet_id', 'round-table-001')->first();

        $this->assertEquals('100.0000', $betRecord->bet);
        $this->assertEquals('80.0000', $betRecord->valid_bet);
    }
}
