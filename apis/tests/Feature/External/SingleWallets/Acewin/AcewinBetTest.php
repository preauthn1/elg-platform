<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Acewin;

use App\Models\TotalBetRecord;
use App\Vendors\AcewinSW\BetErrorCodeEnum;
use App\Vendors\AcewinSW\BetRecordStatusEnum;
use App\Vendors\AcewinSW\ErrorCodeEnum;
use App\Vendors\Models\AcewinBetRecord;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * AcewinSW bet 回調（投注與結算同一支）
 *
 * 案例取自 TPower 生產原文（`ext-webapi-container` 的 `api/sw/acewin/bet 請求紀錄`）。
 * 生產流量目前仍由舊 API_External 承接，新 APIs 這支尚未接手，因此這裡釘的是「接手後必須
 * 等價」的行為。
 */
class AcewinBetTest extends AcewinTestCase
{
    #[TestDox('一般投注結算：扣本金、加派彩，注單直接落在已結算狀態')]
    #[Group('rules/vendor-acewin#R-8')]
    public function test_bet_settles_in_one_call(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $this->postJson("{$this->baseurl}/bet", $this->betPayload('157488032876', '0.1', '0.152'), $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);

        // 1000 - 0.1 + 0.152
        $this->assertEquals('1000.0520', $this->member->refresh()->balance);

        $betRecord = AcewinBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::SETTLE->value, $betRecord->status);
        $this->assertEquals('0.1000', $betRecord->bet);
        $this->assertEquals('0.1000', $betRecord->valid_bet);
        $this->assertEquals('0.0520', $betRecord->win_lose);
        $this->assertEquals(1, TotalBetRecord::count());
    }

    #[TestDox('betAmount=0 的免費旋轉派彩照收，有效投注記 0 但錢要進帳')]
    #[Group('rules/vendor-acewin#R-9')]
    public function test_zero_stake_free_spin_is_paid_out(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        // 生產原文：betAmount=0、winloseAmount=3.612（round 157488032889）
        $this->postJson("{$this->baseurl}/bet", $this->betPayload('157488032889', '0', '3.612'), $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);

        $this->assertEquals('1003.6120', $this->member->refresh()->balance);

        $betRecord = AcewinBetRecord::first();
        $this->assertEquals('0.0000', $betRecord->bet);
        $this->assertEquals('0.0000', $betRecord->valid_bet);
        $this->assertEquals('3.6120', $betRecord->win_lose);
    }

    #[TestDox('同一 round 重送只記一筆，回 REPEAT_TRANSACTION 且不重複加減錢')]
    #[Group('rules/vendor-acewin#R-11')]
    public function test_duplicate_round_is_rejected(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $payload = $this->betPayload('157488032876', '0.1', '0.152');

        $this->postJson("{$this->baseurl}/bet", $payload, $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);
        $this->postJson("{$this->baseurl}/bet", $payload, $this->authHeaders())
            ->assertJson(['errorCode' => BetErrorCodeEnum::REPEAT_TRANSACTION->value]);

        $this->assertEquals('1000.0520', $this->member->refresh()->balance);
        $this->assertEquals(1, AcewinBetRecord::count());
    }

    #[TestDox('餘額不足時整筆拒收，不留下半筆注單')]
    #[Group('rules/vendor-acewin#R-10')]
    public function test_bet_is_rejected_when_balance_is_insufficient(): void
    {
        $this->member->update(['balance' => '0.0500']);

        $this->postJson("{$this->baseurl}/bet", $this->betPayload('157488032876', '0.1', '0.152'), $this->authHeaders())
            ->assertJson(['errorCode' => BetErrorCodeEnum::INSUFFICIENT_BALANCE->value]);

        $this->assertEquals('0.0500', $this->member->refresh()->balance);
        $this->assertEquals(0, AcewinBetRecord::count());
    }

    #[TestDox('round 以 JSON 數字送達時，bet_id 仍必須以字串綁定（唯一索引可用性）')]
    public function test_bet_id_is_bound_as_string_when_round_arrives_as_json_number(): void
    {
        // AcewinSW 生產回調的 round 是 JSON 數字（同一包的 id 反而是字串），而 bet_id 是
        // varchar(191)。綁 int 會讓 MySQL 改以浮點數比較並整欄 CAST，唯一索引失效退化成全表
        // 掃描——TPower 生產實測 1,086 萬列、單次 16 秒，超過三方 5 秒逾時後無限重送。
        $round = 157652076294;
        $this->member->update(['balance' => '100.0000']);

        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (str_contains($query->sql, 'acewin_bet_records') && str_contains($query->sql, 'bet_id')) {
                $bindings = array_merge($bindings, $query->bindings);
            }
        });

        $payload = $this->betPayload((string) $round, '0.1', '0');
        $payload['round'] = $round;

        $this->postJson("{$this->baseurl}/bet", $payload, $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);

        $this->assertContains((string) $round, $bindings, 'bet_id 必須以字串綁定');
        $this->assertNotContains($round, $bindings, 'bet_id 綁成 int 會讓唯一索引失效');
    }
}
