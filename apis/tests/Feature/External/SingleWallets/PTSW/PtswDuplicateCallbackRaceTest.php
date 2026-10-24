<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Vendors\Models\PtswBetRecord;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

class PtswDuplicateCallbackRaceTest extends PtswTestCase
{
    #[Group('rules/vendor-ptsw#R-14')]
    public function test_bet_acquires_and_releases_named_lock(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        DB::enableQueryLog();

        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-lock-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'live_lock_0001',
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-lock-1',
            'amount' => '10.0',
            'transactionDate' => '2026-07-03T10:00:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
        $this->assertStringContainsString('GET_LOCK', $queries, 'bet 應取 named lock 序列化同局回調');
        $this->assertStringContainsString('RELEASE_LOCK', $queries, 'bet 結束應釋放 named lock');
    }

    #[Group('rules/vendor-ptsw#R-13')]
    public function test_duplicate_transaction_code_does_not_double_accumulate(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $round = 'live_dup_0001';
        $payload = [
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => $round,
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-dup-1',
            'amount' => '10.0',
            'transactionDate' => '2026-07-03T10:00:00Z',
        ];

        $this->postJson("{$this->baseurl}/bet", ['requestId' => 'req-a'] + $payload)
            ->assertStatus(200)->assertJsonMissing(['error']);
        // 同 tc 重送（不同 requestId，模擬 vendor 重試）
        $this->postJson("{$this->baseurl}/bet", ['requestId' => 'req-b'] + $payload)
            ->assertStatus(200);

        $this->assertEquals(1, PtswBetRecord::count(), '同 tc 重送不應產生新注單');
        $this->assertEquals('10.0000', PtswBetRecord::first()->bet, '同 tc 重送 BR.bet 不重複累加（仍為 10）');
    }

    #[Group('rules/vendor-ptsw#R-14')]
    public function test_settlement_acquires_named_lock(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $round = 'live_settle_0001';
        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-s-bet', 'username' => $this->username, 'externalToken' => $this->token,
            'gameRoundCode' => $round, 'gameCodeName' => 'ubal', 'transactionCode' => 'tc-s-bet',
            'amount' => '10.0', 'transactionDate' => '2026-07-03T10:00:00Z',
        ])->assertStatus(200);

        DB::enableQueryLog();
        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-s-settle', 'username' => $this->username, 'externalToken' => $this->token,
            'gameRoundCode' => $round,
            'gameRoundClose' => ['date' => '2026-07-03 10:00:30.000'],
            'pay' => ['type' => 'WIN', 'amount' => '0', 'transactionCode' => 'tc-s-settle', 'transactionDate' => '2026-07-03T10:00:30Z'],
        ])->assertStatus(200);

        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' | ');
        $this->assertStringContainsString('GET_LOCK', $queries, 'settlement 應取 named lock');
        $this->assertStringContainsString('RELEASE_LOCK', $queries, 'settlement 應釋放 named lock');
    }

    /**
     * settlement 讀同局注單必須 lockForUpdate：named lock 只序列化回調與 refund-unsettled 命令，
     * reconcile／人工命令直接改 ptsw_bet_records 不走那把鎖；bet 路徑 firstOrNewBetRecord 已上列鎖，settlement 要對齊。
     */
    public function test_settlement_reads_bet_record_for_update(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $round = 'live_settle_rowlock_0001';
        $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-rl-bet', 'username' => $this->username, 'externalToken' => $this->token,
            'gameRoundCode' => $round, 'gameCodeName' => 'ubal', 'transactionCode' => 'tc-rl-bet',
            'amount' => '10.0', 'transactionDate' => '2026-07-03T10:00:00Z',
        ])->assertStatus(200)->assertJsonMissing(['error']);

        DB::enableQueryLog();
        $this->postJson("{$this->baseurl}/settlement", [
            'requestId' => 'req-rl-settle', 'username' => $this->username, 'externalToken' => $this->token,
            'gameRoundCode' => $round,
            'gameRoundClose' => ['date' => '2026-07-03 10:00:30.000'],
            'pay' => ['type' => 'WIN', 'amount' => '0', 'transactionCode' => 'tc-rl-settle', 'transactionDate' => '2026-07-03T10:00:30Z'],
        ])->assertStatus(200)->assertJsonMissing(['error']);

        // 只要求「取來更新的那次讀取」帶列鎖；日後若多一條純查詢用的無鎖讀不在此限
        $lockedBetRecordReads = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql): bool => str_starts_with($sql, 'select')
                && str_contains($sql, '`ptsw_bet_records`')
                && str_contains($sql, '`bet_id` = ?')
                && str_contains($sql, '`member_id` = ?')
                && str_ends_with($sql, 'for update'));
        $this->assertCount(1, $lockedBetRecordReads, 'settlement 讀同局注單必須 lockForUpdate（且只鎖讀一次）');
    }
}
