<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WFSW;

use App\Vendors\Models\WfswBetRecord;
use App\Vendors\WFSW\OperatorStatusEnum;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * WFSW 的 BetId 以 JSON 數字送達（生產原文：`{"BetId": 191008158928, "RoundId": 191008158928, ...}`），
 * 而 wfsw_bet_records.bet_id 是 varchar。綁整數會讓唯一索引失效退化成全表掃描（target/tickets#4408）。
 *
 * WFController 沒有任何 validate()，`$request->all()` 的型別直達查詢條件，
 * 故此處釘的是 controller 自己的轉型責任。
 *
 * php artisan test tests/Feature/External/SingleWallets/WFSW --compact
 */
class WFSWBetIdBindingTest extends WFSWTestCase
{
    private function betPayload(int|string $betId): array
    {
        $data = array_merge($this->basePostData(), [
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameCode' => $this->gameCode,
            'GameType' => 'Slots',
            'BetAmount' => 0.4,
            'ExchangeRate' => 1.0,
            'TranDateTime' => '2026-09-22 04:54:20',
        ]);
        $this->regenerateSignature('Bet', $data);

        return $data;
    }

    #[TestDox('BetId 以 JSON 數字送達時，bet_id 仍必須以字串綁定（唯一索引可用性）')]
    #[Group('rules/vendor-wf#R-7')]
    #[Group('rules/vendor-wf#R-9')]
    public function test_bet_id_is_bound_as_string_when_bet_id_arrives_as_json_number(): void
    {
        $betId = 191008158928;
        $this->member->update(['balance' => '100.0000']);

        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (str_contains($query->sql, 'wfsw_bet_records') && str_contains($query->sql, 'bet_id')) {
                $bindings = array_merge($bindings, $query->bindings);
            }
        });

        $this->postJson("{$this->baseurl}/Bet", $this->betPayload($betId))
            ->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertSame(1, WfswBetRecord::count());
        $this->assertContains((string) $betId, $bindings, 'bet_id 必須以字串綁定');
        $this->assertNotContains($betId, $bindings, 'bet_id 綁成 int 會讓唯一索引失效');
    }
}
