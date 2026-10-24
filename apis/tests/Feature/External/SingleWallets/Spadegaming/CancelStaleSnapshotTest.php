<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 撤單的重複退款閘門必須讀得到「併發的另一筆 cancel 剛 commit 的撤單列」（target/op_tickets#3098）。
 *
 * cancel 的交易一進去就由 WalletService::getMemberLockedWallets() 的一般讀（$member->agent）建立
 * REPEATABLE READ 快照，之後才等會員列鎖；拿到鎖後若仍以一般讀判斷「這筆下注撤過沒」，看的是那張舊快照，
 * 對手剛 commit 的撤單列不在裡面 → 閘門放行 → 再退一次。同型事故見 PegasusSW（target/op_tickets#3075、
 * commit 8c86a78e1）。生產那 9 筆重複撤單有 6 筆 created_at 落在同一秒，就是這條路徑，而循序重送的測試
 * （CancelTest）永遠測不到它。
 *
 * 手法：測試交易（DatabaseTransactions）本身就是「先建快照的交易」，撤單列改由第二條連線 commit，
 * 模擬對手在快照建立之後才落地。
 */
class CancelStaleSnapshotTest extends SpadegamingSWTestCase
{
    private const PEER_CONNECTION = 'spadegamingsw_stale_snapshot_peer';

    private string $placeBetTransferId;

    private string $rivalCancelTransferId;

    private string $retryCancelTransferId;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = Str::upper(Str::random(12));
        $this->placeBetTransferId = 'SGSW-STALE-BET-' . $suffix;
        $this->rivalCancelTransferId = 'SGSW-STALE-RIVAL-' . $suffix;
        $this->retryCancelTransferId = 'SGSW-STALE-RETRY-' . $suffix;
    }

    /** R-22：同一筆下注已被取消過，再收到針對它的取消回調時（即使轉帳編號不同），回當前餘額與成功碼 0，不再退款；兩筆取消幾乎同時到達時也只退一次。 */
    #[Group('rules/vendor-spadegamingsw#R-22')]
    #[TestDox('對手的撤單在快照之後才 commit：重送的撤單仍須被擋下，錢包不得再退一次')]
    public function test_cancel_is_rejected_when_rival_cancel_committed_after_transaction_snapshot(): void
    {
        $walletBalance = 1000.05;
        $this->wallet->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->delete();

        // placeBet 走正常流程，同時它內部的 $member->agent 一般讀已為本測試交易釘住 REPEATABLE READ 快照
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::PLACE_BET,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 300,
            'transferTime' => date('Y-m-d H:i:s'),
        ], $this->headers)->assertStatus(200)->assertJsonPath('code', 0);

        $this->wallet->refresh();
        $balanceAfterPlaceBet = $this->wallet->balance;

        $this->assertNull(
            SpadegamingswBetRecord::query()->where('bet_id', $this->rivalCancelTransferId)->first(),
            '前置：對手的撤單列此刻不該在本交易的快照裡'
        );

        $this->commitRivalCancelOutsideTestTransaction();

        // 三方換新 transferId 重送同一筆撤單
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->retryCancelTransferId,
            'referenceId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::CANCEL,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 300,
            'transferTime' => date('Y-m-d H:i:s'),
        ], $this->headers);

        $response->assertStatus(200)->assertJsonPath('code', 0);

        $this->wallet->refresh();
        $this->assertEquals($balanceAfterPlaceBet, $this->wallet->balance, '對手已退過款，這次重送不得再退一次');

        $this->assertNull(
            SpadegamingswBetRecord::query()
                ->where('bet_id', $this->retryCancelTransferId)
                ->lockForUpdate()
                ->first(),
            '被擋下的重送不得再寫一筆撤單列'
        );
    }

    /**
     * 用測試交易以外的連線 commit 一筆撤單列，模擬併發的對手在本交易的快照建立之後才落地。
     *
     * 清理註冊在 beforeApplicationDestroyed：它會排在 DatabaseTransactions 的 rollBack 之後執行，
     * 順序反過來會卡在測試交易持有的列鎖上。
     */
    private function commitRivalCancelOutsideTestTransaction(): void
    {
        $default = (string) config('database.default');
        config(['database.connections.' . self::PEER_CONNECTION => config("database.connections.{$default}")]);
        $peer = DB::connection(self::PEER_CONNECTION);

        $now = date('Y-m-d H:i:s');
        $rivalId = $peer->table('spadegamingsw_bet_records')->insertGetId([
            'bet_id' => $this->rivalCancelTransferId,
            'reference_bet_id' => $this->placeBetTransferId,
            'username' => $this->acctId,
            'game_code' => $this->game->game_type,
            'type' => TransferTypeEnum::CANCEL->value,
            'bet' => '0.0000',
            'valid_bet' => '0.0000',
            'win_lose' => '150.0000',
            'rolling_chips' => '0.0000',
            'bet_time' => $now,
            'payout_time' => $now,
            'infos' => '[]',
        ]);

        $this->beforeApplicationDestroyed(function () use ($rivalId) {
            $peer = DB::connection(self::PEER_CONNECTION);
            $peer->table('spadegamingsw_bet_records')->where('id', $rivalId)->delete();
            $peer->disconnect();
        });
    }
}
