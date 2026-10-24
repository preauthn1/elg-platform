<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor\PlayTech;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\TotalBetRecord;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\PtswAccount;
use App\Vendors\Models\PtswBetRecord;
use App\Vendors\Models\PtswDealRecord;
use App\Vendors\PlayTechSW\BetRecordStatusEnum;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Tests\TestCase;

abstract class ReconcileBetAccumulationTestCase extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected PtswAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PTSW->value);
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->save();

        $this->account = PtswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => 'ptsw00000001',
            'status' => 1,
            'admin' => 1,
            'member_id' => $this->member->id,
            'token' => 'token',
        ]);
    }

    /**
     * 建立一筆受影響的注單組合：1 BR + 1 TBR + 2 deal_records（type='bet'）。
     *
     * - BR.bet/valid_bet = $currentBet（被覆寫的最後一手）
     * - deal_records SUM(bet_amount) = $expectedBet（真實累計）
     * - TBR.bet/valid_bet 跟 BR 一致
     *
     * 所有時間放在 Taipei 2026-05-08 中午（UTC 04:00），確保落在測試指定的 --from/--to 區間內。
     */
    protected function seedAffectedRecords(string $currentBet, string $expectedBet): int
    {
        $betTimeUtc = '2026-05-08 04:00:00';
        $betId = 'live_test_1500500508000000';
        $game = $this->vendor->games()->first();

        $br = PtswBetRecord::create([
            'member_id' => $this->member->id,
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => 'tc-last',
            'game_code' => $game->game_type,
            'bet' => $currentBet,
            'valid_bet' => $currentBet,
            'win_lose' => bcmul($currentBet, '-1', 4),
            'rolling_chips' => 0,
            'jackpot' => 0,
            'bet_time' => $betTimeUtc,
            'payout_time' => $betTimeUtc,
            'status' => BetRecordStatusEnum::SETTLE->value,
        ]);

        TotalBetRecord::create([
            'vendor_id' => VendorIdEnum::PTSW->value,
            'bet_record_id' => $br->id,
            'member_id' => $this->member->id,
            'game_id' => $game->id,
            'reference_no' => $betId,
            'summary_time' => $betTimeUtc,
            'bet_time' => $betTimeUtc,
            'payout_time' => $betTimeUtc,
            'bet' => $currentBet,
            'valid_bet' => $currentBet,
            'win_lose' => bcmul($currentBet, '-1', 4),
        ]);

        // 兩筆 deal_records (type='bet')，金額加總 = expectedBet
        $half = bcdiv($expectedBet, '2', 4);
        $remainder = bcsub($expectedBet, $half, 4);
        foreach ([$half, $remainder] as $i => $amount) {
            PtswDealRecord::create([
                'request_id' => 'req-' . $i,
                'username' => $this->account->username,
                'token' => $this->account->token,
                'bet_id' => $betId,
                'transaction_code' => 'tc-' . $i,
                'type' => 'bet',
                'bet_amount' => $amount,
                'win_amount' => 0,
                'jackpot' => 0,
                'external_transaction_code' => 'ext-' . $i,
                'external_transaction_date' => Carbon::parse($betTimeUtc)->format('Y-m-d H:i:s.v'),
                'applied_at' => $betTimeUtc,
                'raw' => [],
            ]);
        }

        return $br->id;
    }

    /**
     * 模擬 refund 場景的 deal_records 組合：
     *   placeBet → bet_amount=+X (type='bet')
     *   refund   → bet_amount=-X (type='gameRoundResult')
     * 加總為 0，BR.bet 也應為 0（settle 階段累加沖銷後）。
     */
    protected function seedRefundRound(string $placeBetAmount, string $currentBet): int
    {
        $betTimeUtc = '2026-05-08 04:00:00';
        $betId = 'refund_test_' . bin2hex(random_bytes(4));
        $game = $this->vendor->games()->first();

        $br = PtswBetRecord::create([
            'member_id' => $this->member->id,
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => 'tc-refund',
            'game_code' => $game->game_type,
            'bet' => $currentBet,
            'valid_bet' => $currentBet,
            'win_lose' => '0',
            'rolling_chips' => 0,
            'jackpot' => 0,
            'bet_time' => $betTimeUtc,
            'payout_time' => $betTimeUtc,
            'status' => BetRecordStatusEnum::SETTLE->value,
        ]);

        TotalBetRecord::create([
            'vendor_id' => VendorIdEnum::PTSW->value,
            'bet_record_id' => $br->id,
            'member_id' => $this->member->id,
            'game_id' => $game->id,
            'reference_no' => $betId,
            'summary_time' => $betTimeUtc,
            'bet_time' => $betTimeUtc,
            'payout_time' => $betTimeUtc,
            'bet' => $currentBet,
            'valid_bet' => $currentBet,
            'win_lose' => '0',
        ]);

        // placeBet 事件：bet_amount = +X
        PtswDealRecord::create([
            'request_id' => 'req-placebet',
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => 'tc-placebet',
            'type' => 'bet',
            'bet_amount' => $placeBetAmount,
            'win_amount' => 0,
            'jackpot' => 0,
            'external_transaction_code' => 'ext-placebet',
            'external_transaction_date' => Carbon::parse($betTimeUtc)->format('Y-m-d H:i:s.v'),
            'applied_at' => $betTimeUtc,
            'raw' => [],
        ]);

        // refund 沖銷事件：bet_amount = -X (PlayTechController createDealRecord 在 REFUND 路徑寫入)
        // settlement 路徑不更新 applied_at（永遠 NULL），對齊真實行為
        $refundDeal = PtswDealRecord::create([
            'request_id' => 'req-refund',
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => 'tc-refund',
            'type' => 'gameRoundResult',
            'bet_amount' => bcmul($placeBetAmount, '-1', 4),
            'win_amount' => 0,
            'jackpot' => 0,
            'external_transaction_code' => 'ext-refund',
            'external_transaction_date' => Carbon::parse($betTimeUtc)->format('Y-m-d H:i:s.v'),
            'applied_at' => null,
            'raw' => [],
        ]);
        $refundDeal->timestamps = false;
        $refundDeal->created_at = $betTimeUtc;
        $refundDeal->save();

        return $br->id;
    }

    /**
     * 把該局的 refund 沖銷事件 raw 改成 NULL（舊資料或極端情形）；它仍是生效的沖銷，SQL 的 `raw NOT LIKE` 對 NULL 回 NULL，
     * 少了 `whereNull('raw')` 這筆會被濾掉 → expected_bet 變成 placeBet 金額 → 補帳把已沖成 0 的 BR.bet 補回去（2026-05 overshoot 事故形狀）。
     */
    protected function nullifyRefundEventRaw(string $betId): void
    {
        PtswDealRecord::query()
            ->where('bet_id', $betId)
            ->where('type', 'gameRoundResult')
            ->update(['raw' => null]);
    }

    /**
     * 種一筆「被 settlement 拒絕的 REFUND」留下的 gameRoundResult 事件：
     * 形狀與生效的退款沖銷事件相同（bet_amount 為負、applied_at NULL），只差 raw 帶 `_rejected`。
     * 它沒動過錢包與 BR，補帳 SQL 不得把它算進 expected_bet。
     */
    protected function seedRejectedRefundEvent(string $betId, string $betAmount): void
    {
        $betTimeUtc = '2026-05-08 04:00:00';

        $rejected = PtswDealRecord::create([
            'request_id' => 'req-rejected-refund',
            'username' => $this->account->username,
            'token' => $this->account->token,
            'bet_id' => $betId,
            'transaction_code' => 'tc-rejected-refund',
            'type' => 'gameRoundResult',
            'bet_amount' => $betAmount,
            'win_amount' => 0,
            'jackpot' => 0,
            'external_transaction_code' => 'ext-rejected-refund',
            'external_transaction_date' => Carbon::parse($betTimeUtc)->format('Y-m-d H:i:s.v'),
            'applied_at' => null,
            'raw' => [
                'gameRoundCode' => $betId,
                'pay' => ['transactionCode' => 'tc-rejected-refund', 'type' => 'REFUND', 'relatedTransactionCode' => 'tc-0'],
                '_rejected' => ['code' => 'ERR_NO_BET', 'reason' => 'seeded for test'],
            ],
        ]);
        $rejected->timestamps = false;
        $rejected->created_at = $betTimeUtc;
        $rejected->save();
    }
}
