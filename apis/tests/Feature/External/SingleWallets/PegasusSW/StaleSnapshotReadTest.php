<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PegasusSW;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\PgsswBetRecord;
use App\Vendors\Models\PgsswCancelLog;
use App\Vendors\PegasusSW\BetRecordStatusEnum;
use App\Vendors\PegasusSW\OperatorStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 重現 2026-09-05 TPower 事故的競態（target/op_tickets#3075）：placeBet 佔住會員鎖時 target 逾時先送 cancel，
 * cancel 的交易一進去就用一般讀（`$member->agent`）建立了 REPEATABLE READ 快照，等到會員鎖後再用一般讀
 * 查注單，看不到剛 commit 的那筆，走「查無」分支回 OK，錢沒退、target 也不再重送。
 *
 * 測試交易（DatabaseTransactions）本身就是那個「先建快照的交易」；注單與它的 total_bet_records 改由
 * 第二條連線 commit，模擬 placeBet 在快照建立之後才落地。查注單若不是鎖定讀，會重現「明明有注單卻當查無」；
 * 寫 total_bet_records 若仍用一般讀判斷存在與否，會看不到 placeBet 那列而 INSERT 撞唯一鍵，整筆交易炸掉。
 *
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/StaleSnapshotReadTest.php --compact
 */
class StaleSnapshotReadTest extends PgsswTestCase
{
    private const PEER_CONNECTION = 'pgssw_stale_snapshot_peer';

    /** 用來扮演「別的遊戲商」的 vendor_id，只需與 PGSSW 不同 */
    private const OTHER_VENDOR_ID = 999001;

    /** 每個測試各自的 bet_id：第二條連線的 INSERT 是真 commit，固定值在清理失敗時會毒到同目錄其他測試 */
    private string $betId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->betId = 'TXN-STALE-' . Str::upper(Str::random(12));
    }

    #[TestDox('cancel 進場後才 commit 的注單：仍須退還注金、status=ROLLBACK、CancelLog status=1、TBR 沖銷')]
    #[Group('rules/vendor-target#R-14')]
    public function test_cancel_rolls_back_bet_committed_after_transaction_snapshot(): void
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);

        $this->pinSnapshotBeforePeerCommit();
        $this->commitBetRecordOutsideTestTransaction();

        $response = $this->postWithIdentity('/bet/cancel', [
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => $this->betId,
            'gameID' => 1234,
        ]);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => OperatorStatusEnum::OK->value]);
        $this->assertSame(Common::numAdd($balanceAfterBet, '10.0000', 4), $this->member->balance, '注金必須退回');

        $betRecord = $this->readBetRecordWithLock();
        $this->assertSame(BetRecordStatusEnum::ROLLBACK->value, $betRecord->status);
        $this->assertSame('0.0000', $betRecord->bet);
        $this->assertSame(1, PgsswCancelLog::query()->where('transaction_no', $this->betId)->value('status'));
        $this->assertSame(1, TransactionLog::count());

        $totalBetRecord = $this->readTotalBetRecordWithLock($betRecord);
        $this->assertSame('0.0000', $totalBetRecord->bet, 'TBR 必須跟著沖銷，不是另插一筆');
        $this->assertSame('0.0000', $totalBetRecord->win_lose);
    }

    #[TestDox('settle 進場後才 commit 的注單：仍須派彩、status=SETTLE、TBR 更新，不得回 INVALID_STATUS')]
    #[Group('rules/vendor-target#R-14')]
    public function test_settle_pays_out_bet_committed_after_transaction_snapshot(): void
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);

        $this->pinSnapshotBeforePeerCommit();
        $this->commitBetRecordOutsideTestTransaction();

        $response = $this->postWithIdentity('/bet/settle', [
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => $this->betId,
            'gameID' => 1234,
            'betAmount' => 10.0,
            'payoutAmount' => 20.0,
            'validAmount' => 10.0,
            'taxAmount' => 0.0,
            'settlementTime' => '2024-09-30T14:00:00Z',
        ]);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => OperatorStatusEnum::OK->value]);
        $this->assertSame(Common::numAdd($balanceAfterBet, '20.0000', 4), $this->member->balance, '派彩必須入帳');

        $betRecord = $this->readBetRecordWithLock();
        $this->assertSame(BetRecordStatusEnum::SETTLE->value, $betRecord->status);
        $this->assertSame('20.0000', $betRecord->win);
        $this->assertSame(1, TransactionLog::count());

        $totalBetRecord = $this->readTotalBetRecordWithLock($betRecord);
        $this->assertSame('10.0000', $totalBetRecord->win_lose, 'TBR 必須更新成結算後的輸贏');
    }

    #[TestDox('settle-change 進場後才 commit 的已結算注單：仍須改派彩、TBR 更新，不得回 BET_NOT_FOUND')]
    #[Group('rules/vendor-target#R-13')]
    public function test_settle_change_updates_bet_committed_after_transaction_snapshot(): void
    {
        $balanceAfterSettle = '1010.0000';
        $this->member->update(['balance' => $balanceAfterSettle]);

        $this->pinSnapshotBeforePeerCommit();
        $this->commitBetRecordOutsideTestTransaction([
            'win' => '20.0000',
            'win_lose' => '10.0000',
            'payout_time' => '2024-09-30 14:00:00',
            'settlement_time' => '2024-09-30 14:00:00',
            'status' => BetRecordStatusEnum::SETTLE->value,
        ], [
            'win_lose' => '10.0000',
            'payout_time' => '2024-09-30 14:00:00',
        ]);

        $response = $this->postWithIdentity('/bet/settle-change', [
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => $this->betId,
            'gameID' => 1234,
            'afterBetAmount' => 10.0,
            'afterValidAmount' => 10.0,
            'afterPayoutAmount' => 30.0,
            'settlementTime' => '2024-09-30T14:05:00Z',
        ]);
        $this->member->refresh();

        $response->assertStatus(200)->assertJson(['code' => OperatorStatusEnum::OK->value]);
        $this->assertSame(Common::numAdd($balanceAfterSettle, '10.0000', 4), $this->member->balance, '派彩差額必須入帳');

        $betRecord = $this->readBetRecordWithLock();
        $this->assertSame('30.0000', $betRecord->win);
        $this->assertSame(BetRecordStatusEnum::SETTLE->value, $betRecord->status);

        $totalBetRecord = $this->readTotalBetRecordWithLock($betRecord);
        $this->assertSame('20.0000', $totalBetRecord->win_lose, 'TBR 必須更新成改派彩後的輸贏');
    }

    #[TestDox('cancel 進場後才 commit 的進行中現金紀錄：仍須被關閉，不留下兩筆進行中紀錄')]
    public function test_cancel_closes_gaming_transaction_log_committed_after_transaction_snapshot(): void
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);

        $this->pinSnapshotBeforePeerCommit();
        $this->commitBetRecordOutsideTestTransaction();
        $gamingLogId = $this->commitGamingTransactionLogOutsideTestTransaction();
        $otherVendorLogId = $this->commitGamingTransactionLogOutsideTestTransaction(self::OTHER_VENDOR_ID);

        $response = $this->postWithIdentity('/bet/cancel', [
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => $this->betId,
            'gameID' => 1234,
        ]);

        $response->assertStatus(200)->assertJson(['code' => OperatorStatusEnum::OK->value]);

        // placeBet 那筆進行中紀錄必須被關閉。關閉走 UPDATE（當前讀）本來就看得到它，
        // 舊行為卻因為「查不到上一筆交易紀錄」而整段提早返回，連帶把關閉一起跳過，
        // 讓同一回合留下兩筆狀態 999，SR14 現金明細因此多一列。
        $gamingLog = TransactionLog::query()->where('id', $gamingLogId)->lockForUpdate()->first();
        $this->assertNotNull($gamingLog);
        $this->assertSame(
            TransactionStatusEnum::STATUS_SUCCESS->value,
            $gamingLog->status,
            'placeBet 的進行中現金紀錄必須被關閉'
        );

        // 關閉只針對本遊戲商，別的遊戲商的進行中紀錄不能被波及（拿掉 vendor_id 條件就會紅）
        $this->assertSame(
            TransactionStatusEnum::STATUS_GAMING->value,
            TransactionLog::query()->where('id', $otherVendorLogId)->lockForUpdate()->value('status'),
            '別的遊戲商的進行中紀錄不該被關閉'
        );
    }

    #[TestDox('上一筆進行中紀錄屬於別的遊戲商時：本遊戲商那筆仍須被關閉')]
    public function test_cancel_closes_own_vendor_gaming_log_when_last_log_belongs_to_another_vendor(): void
    {
        $balanceAfterBet = '990.0000';
        $this->member->update(['balance' => $balanceAfterBet]);

        // 本交易自己寫的列一定看得到，用它扮演「快照裡查得到的上一筆」：別的遊戲商、狀態 999。
        // 有了它，關閉就不再走「查不到上一筆」那條路，而是走合併判定分支。
        $otherVendorLogId = $this->makeGamingTransactionLog(vendorId: self::OTHER_VENDOR_ID);

        $this->pinSnapshotBeforePeerCommit();
        $this->commitBetRecordOutsideTestTransaction();
        $ownVendorLogId = $this->commitGamingTransactionLogOutsideTestTransaction();

        $response = $this->postWithIdentity('/bet/cancel', [
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => $this->betId,
            'gameID' => 1234,
        ]);

        $response->assertStatus(200)->assertJson(['code' => OperatorStatusEnum::OK->value]);

        // 上一筆屬於別的遊戲商，不會被合併，而是直接關閉——本遊戲商那筆也該一起關掉，
        // 否則同一會員會同時留下兩筆進行中紀錄。
        $this->assertSame(
            TransactionStatusEnum::STATUS_SUCCESS->value,
            TransactionLog::query()->where('id', $otherVendorLogId)->lockForUpdate()->value('status'),
            '別的遊戲商那筆本來就會被關閉'
        );
        $this->assertSame(
            TransactionStatusEnum::STATUS_SUCCESS->value,
            TransactionLog::query()->where('id', $ownVendorLogId)->lockForUpdate()->value('status'),
            'placeBet 的進行中現金紀錄必須被關閉'
        );
    }

    /**
     * 先用一般讀查一次目標注單：既確認前置條件（此時還沒有這筆），也確保測試交易的
     * REPEATABLE READ 快照已在第二條連線 commit 之前建立，之後的一般讀都看不到那筆。
     */
    private function pinSnapshotBeforePeerCommit(): void
    {
        $this->assertNull(PgsswBetRecord::query()->where('bet_id', $this->betId)->first());
    }

    /**
     * 用測試交易以外的連線 commit 一筆注單與對應的 total_bet_records（placeBet 會在同一交易寫這兩張），
     * 模擬 placeBet 在快照之後落地，並在測試交易回滾之後刪掉它們。
     *
     * 清理必須排在 DatabaseTransactions 的 rollBack 之後：那個 callback 在 setUp 就註冊了，
     * 這裡註冊的會在它之後執行；順序反過來會卡在測試交易持有的列鎖上。
     */
    private function commitBetRecordOutsideTestTransaction(array $betOverrides = [], array $totalOverrides = []): void
    {
        $peer = $this->peerConnection();

        $betRow = array_merge([
            'vendor_info_id' => $this->vendorInfo->id,
            'bet_id' => $this->betId,
            'session_token' => $this->sessionToken,
            'username' => $this->playerUniqueId,
            'game_code' => '1234',
            'bet' => '10.0000',
            'win' => '0.0000',
            'win_lose' => '-10.0000',
            'rolling_chip' => '0.0000',
            'info' => '[]',
            'bet_time' => '2024-09-30 14:00:00',
            'payout_time' => '1970-01-01 00:00:00',
            'settlement_time' => '1970-01-01 00:00:00',
            'status' => BetRecordStatusEnum::BET->value,
        ], $betOverrides);
        $betRecordId = $peer->table('pgssw_bet_records')->insertGetId($betRow);

        $vendorId = (int) $this->vendorInfo->vendor_id;
        $peer->table('total_bet_records')->insert(array_merge([
            'summary_time' => '2024-09-30 14:00:00',
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
            'bet_record_id' => $betRecordId,
            'game_id' => 0,
            'reference_no' => $this->betId,
            'bet' => $betRow['bet'],
            'valid_bet' => $betRow['bet'],
            'win_lose' => $betRow['win_lose'],
            'rolling_chip' => '0.0000',
            'valid_bet_rate' => 1,
            'bet_time' => $betRow['bet_time'],
            'payout_time' => $betRow['payout_time'],
        ], $totalOverrides));

        $this->beforeApplicationDestroyed(function () use ($betRecordId, $vendorId) {
            $peer = $this->peerConnection();
            $peer->table('total_bet_records')->where('vendor_id', $vendorId)->where('bet_record_id', $betRecordId)->delete();
            $peer->table('pgssw_bet_records')->where('id', $betRecordId)->delete();
            $peer->disconnect();
        });
    }

    /**
     * 用第二條連線 commit 一筆「遊戲進行中」的現金紀錄，模擬 placeBet 在快照之後寫下的那筆。
     *
     * @return int 該筆紀錄的 id
     */
    private function commitGamingTransactionLogOutsideTestTransaction(?int $vendorId = null): int
    {
        $id = $this->peerConnection()
            ->table('transaction_logs')
            ->insertGetId($this->gamingTransactionLogRow($vendorId ?? (int) $this->vendorInfo->vendor_id));

        $this->beforeApplicationDestroyed(function () use ($id) {
            $peer = $this->peerConnection();
            $peer->table('transaction_logs')->where('id', $id)->delete();
            $peer->disconnect();
        });

        return $id;
    }

    /**
     * 由測試交易自己寫一筆「遊戲進行中」的現金紀錄。本交易寫的列自己一定看得到，
     * 用來扮演「快照裡查得到的上一筆」。
     *
     * @return int 該筆紀錄的 id
     */
    private function makeGamingTransactionLog(int $vendorId): int
    {
        return DB::table('transaction_logs')->insertGetId($this->gamingTransactionLogRow($vendorId));
    }

    /** @return array<string, mixed> */
    private function gamingTransactionLogRow(int $vendorId): array
    {
        return [
            'transaction_no' => 'TXNLOG-' . Str::upper(Str::random(12)),
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'vendor_id' => $vendorId,
            'order_id' => 0,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value,
            'point' => '10.0000',
            'tax' => '0.0000',
            'vendor_point' => '10.0000',
            'rate' => $this->vendorInfo->rate,
            'before_balance' => '1000.0000',
            'balance' => '990.0000',
            'vendor_balance' => '990.0000',
            'status' => TransactionStatusEnum::STATUS_GAMING->value,
            'remark' => $this->sessionToken,
            'result' => '0',
            'operator' => $this->playerUniqueId,
            'admin' => 0,
        ];
    }

    /** 取得測試交易以外的第二條連線；設定是複製自預設連線，重複呼叫安全 */
    private function peerConnection(): \Illuminate\Database\Connection
    {
        $default = (string) config('database.default');
        config(['database.connections.' . self::PEER_CONNECTION => config("database.connections.{$default}")]);

        return DB::connection(self::PEER_CONNECTION);
    }

    /** 斷言用的讀取走鎖定讀：測試交易的快照裡本來就沒有這筆，一般讀會拿到 null 而不是結果 */
    private function readBetRecordWithLock(): PgsswBetRecord
    {
        $betRecord = PgsswBetRecord::query()->where('bet_id', $this->betId)->lockForUpdate()->first();
        $this->assertNotNull($betRecord);

        return $betRecord;
    }

    private function readTotalBetRecordWithLock(PgsswBetRecord $betRecord): TotalBetRecord
    {
        $rows = TotalBetRecord::query()
            ->where('vendor_id', $this->vendorInfo->vendor_id)
            ->where('bet_record_id', $betRecord->id)
            ->lockForUpdate()
            ->get();
        $this->assertCount(1, $rows, '同一筆注單只能有一列 TBR');

        return $rows->first();
    }
}
