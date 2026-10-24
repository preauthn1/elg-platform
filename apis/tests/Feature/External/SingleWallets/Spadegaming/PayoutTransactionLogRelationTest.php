<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Models\TotalBetRecord;
use App\Models\TotalBetRecordTransactionLog;
use App\Models\TransactionLog;
use App\Vendors\SpadegamingSW\Enums\TransferTypeEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 派彩後 total_bet_record 與 transaction_log 的關聯測試
 *
 * 輸錢的回合派彩金額為 0，真金沒有淨變動、不會建立新的交易紀錄；本家的下注與派彩又是兩筆
 * 獨立注單，派彩那筆的投注恆為 0。兩者相加使得注單關聯不到任何交易紀錄，SR14 現金紀錄的
 * detail 就永遠列不出該注單（dev 實例：reference_no 26091417324822801188301）。
 */
class PayoutTransactionLogRelationTest extends SpadegamingSWTestCase
{
    private string $placeBetTransferId = '20240801142953555117852';

    private string $payoutTransferId = '20240801142953555117852_type_4';

    /** R-18：輸局派彩 0 時，該局總注單仍關聯到下注當時建立的現金交易紀錄，讓這局能出現在現金紀錄明細。 */
    #[Group('rules/vendor-spadegamingsw#R-18')]
    #[TestDox('輸錢派彩 0 的回合，注單仍關聯到下注時建立的交易紀錄')]
    public function test_losing_payout_relates_total_bet_record_to_place_bet_transaction_log(): void
    {
        $this->wallet->update(['balance' => 1000]);
        $this->rollingChipWallet->update(['balance' => 0]);

        $this->sendPlaceBet(300);
        $this->sendPayout(0);

        $transactionLog = TransactionLog::query()->first();
        $totalBetRecord = TotalBetRecord::query()->first();

        $this->assertNotNull($transactionLog, '下注扣真金時應建立交易紀錄');
        $this->assertNotNull($totalBetRecord, '派彩後應建立注單');
        $this->assertDatabaseHas(TotalBetRecordTransactionLog::class, [
            'total_bet_record_id' => $totalBetRecord->id,
            'transaction_log_id' => $transactionLog->id,
        ]);
    }

    /** R-19：全額以禮碼下注的局，總注單不關聯任何現金交易紀錄。 */
    #[Group('rules/vendor-spadegamingsw#R-19')]
    #[TestDox('全禮碼投注的回合不建立關聯，避免掛到同 session 其他回合的現金紀錄')]
    public function test_rolling_chip_only_bet_does_not_relate_to_any_transaction_log(): void
    {
        $this->wallet->update(['balance' => 1000]);
        $this->rollingChipWallet->update(['balance' => 500]);

        $this->sendPlaceBet(300);
        $this->sendPayout(0);

        $this->assertSame(0, TransactionLog::query()->count(), '全禮碼投注不應動到真金');
        $this->assertSame(1, TotalBetRecord::query()->count());
        $this->assertSame(0, TotalBetRecordTransactionLog::query()->count());
    }

    private function sendPlaceBet(int $amount): void
    {
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::PLACE_BET,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => $amount,
            'transferTime' => date('Y-m-d H:i:s'),
        ], $this->headers);
    }

    private function sendPayout(int $amount): void
    {
        $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $this->defaultSerialNo,
            'transferId' => $this->payoutTransferId,
            'referenceId' => $this->placeBetTransferId,
            'type' => TransferTypeEnum::PAYOUT,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => $amount,
            'transferTime' => date('Y-m-d H:i:s'),
        ], $this->headers);
    }
}
