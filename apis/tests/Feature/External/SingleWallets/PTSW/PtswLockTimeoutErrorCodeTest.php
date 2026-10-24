<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\GameRoundLock;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;

/**
 * 同局 named lock 取不到時，回給 PT 的錯誤碼必須依 action 分流（target/op_tickets#3101）。
 *
 * 判準是「PT 收到之後還會不會再送這一筆」：
 * - `gameRoundResult`（結算）→ `INTERNAL_ERROR`（api.md「9. 錯誤碼」列為可重試），PT 會依退避重送。
 *   若回不可重試的 `ERR_TRANSACTION_DECLINED`，PT 不再送，注單永遠停在進行中而下注款已扣。
 * - `bet`（投注）→ `ERR_TRANSACTION_DECLINED`，讓 PT 視為投注失敗；重試反而可能變成重複下注。
 */
class PtswLockTimeoutErrorCodeTest extends PtswTestCase
{
    #[Group('rules/vendor-ptsw#R-15')]
    public function test_settlement_lock_timeout_returns_retryable_internal_error(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $round = 'live_lock_timeout_settle';

        $holder = $this->holdGameRoundLock($round);

        try {
            $response = $this->postJson("{$this->baseurl}/settlement", [
                'requestId' => 'req-lock-timeout-settle',
                'username' => $this->username,
                'externalToken' => $this->token,
                'gameRoundCode' => $round,
                'gameRoundClose' => ['date' => '2026-09-08 10:00:30.000'],
                'pay' => [
                    'type' => 'WIN',
                    'amount' => '0',
                    'transactionCode' => 'tc-lock-timeout-settle',
                    'transactionDate' => '2026-09-08T10:00:30Z',
                ],
            ]);
        } finally {
            $this->releaseGameRoundLock($holder, $round);
        }

        $response->assertStatus(200)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR');

        $this->assertNoSideEffect($round);
    }

    #[Group('rules/vendor-ptsw#R-15')]
    public function test_bet_lock_timeout_keeps_non_retryable_declined(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $round = 'live_lock_timeout_bet';

        $holder = $this->holdGameRoundLock($round);

        try {
            $response = $this->postJson("{$this->baseurl}/bet", [
                'requestId' => 'req-lock-timeout-bet',
                'username' => $this->username,
                'externalToken' => $this->token,
                'gameRoundCode' => $round,
                'gameCodeName' => 'ubal',
                'transactionCode' => 'tc-lock-timeout-bet',
                'amount' => '10.0',
                'transactionDate' => '2026-09-08T10:00:00Z',
            ]);
        } finally {
            $this->releaseGameRoundLock($holder, $round);
        }

        $response->assertStatus(200)
            ->assertJsonPath('error.code', 'ERR_TRANSACTION_DECLINED');

        $this->assertNoSideEffect($round);
    }

    /**
     * warning log 是本單的交付物之一——在此之前撞鎖只留下 errorResponse() 那筆看不出 action 的
     * error log，正是開單時「線上發生頻率未查」的原因。action 與 error_code 兩個欄位就是它存在的理由。
     */
    #[Group('rules/vendor-ptsw#R-15')]
    public function test_settlement_lock_timeout_logs_action_and_error_code(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $round = 'live_lock_timeout_log';

        Log::spy();

        $holder = $this->holdGameRoundLock($round);

        try {
            $this->postJson("{$this->baseurl}/settlement", [
                'requestId' => 'req-lock-timeout-log',
                'username' => $this->username,
                'externalToken' => $this->token,
                'gameRoundCode' => $round,
                'gameRoundClose' => ['date' => '2026-09-08 10:00:30.000'],
                'pay' => [
                    'type' => 'WIN',
                    'amount' => '0',
                    'transactionCode' => 'tc-lock-timeout-log',
                    'transactionDate' => '2026-09-08T10:00:30Z',
                ],
            ])->assertStatus(200);
        } finally {
            $this->releaseGameRoundLock($holder, $round);
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(static function (string $message, array $context) use ($round): bool {
                return str_contains($message, 'PTSW 同局鎖逾時')
                    && str_contains($message, $round)
                    && ($context['action'] ?? null) === 'gameRoundResult'
                    && ($context['error_code'] ?? null) === 'INTERNAL_ERROR';
            })
            ->once();
    }

    /**
     * 取鎖失敗發生在 runDealAction 之外，故連 deal record 都不該建立——
     * 這正是「PT 不重送就等於整筆結算永久遺失」的原因：我方沒有任何紀錄可事後補。
     */
    private function assertNoSideEffect(string $round): void
    {
        $this->assertSame(0, PtswDealRecord::where('bet_id', $round)->count());
        $this->assertSame(0, PtswBetRecord::where('bet_id', $round)->count());
        $this->assertSame('1000.0000', (string) $this->member->refresh()->balance, '取不到鎖不得動錢包');
    }

    private function holdGameRoundLock(string $round): Connection
    {
        $lockKey = GameRoundLock::key($round, $this->account->username);
        $holder = $this->openLockHolderConnection();

        $this->assertSame(
            1,
            (int) $holder->selectOne('SELECT GET_LOCK(?, 0) AS l', [$lockKey], false)->l,
            '前置：第二條 session 必須先拿到鎖'
        );

        return $holder;
    }

    private function releaseGameRoundLock(Connection $holder, string $round): void
    {
        $holder->selectOne('SELECT RELEASE_LOCK(?) AS r', [GameRoundLock::key($round, $this->account->username)], false);
        DB::purge('lock_holder');
    }

    /**
     * 開第二條 DB session（複製預設連線設定）：MySQL named lock 以 session 為單位，
     * 同一條連線再 GET_LOCK 會直接成功，只有另一條 session 持鎖才能讓受測請求真的撞上逾時。
     */
    private function openLockHolderConnection(): Connection
    {
        $default = (string) config('database.default');
        config(['database.connections.lock_holder' => config("database.connections.{$default}")]);

        return DB::connection('lock_holder');
    }
}
