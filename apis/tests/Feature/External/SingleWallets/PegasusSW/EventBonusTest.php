<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PegasusSW;

use App\Helpers\Common;
use App\Models\TransactionLog;
use App\Vendors\Models\PgsswBetRecord;
use App\Vendors\Models\PgsswEventRecord;
use App\Vendors\PegasusSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 活動獎勵派彩（/target/transaction/event-bonus）
 *
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/EventBonusTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW --compact
 */
class EventBonusTest extends PgsswTestCase
{
    private function postData(array $overrides = []): array
    {
        return array_merge([
            'playerUniqueID' => $this->playerUniqueId,
            'transactionNo' => 'TXN-EVENT-001',
            'amount' => 100.0,
            'eventDetailInfo' => [
                'receiveTime' => '2024-09-30T14:00:00Z',
                'eventType' => 'cashback',
                'eventId' => 'EVT-001',
            ],
        ], $overrides);
    }

    #[TestDox('首次 event-bonus 派彩：加回 amount、建立 betRecord(bet=0)、寫入 EventRecord')]
    #[Group('rules/vendor-target#R-19')]
    public function test_event_bonus_credits_amount_and_creates_records()
    {
        $balance = '1000.0000';
        $this->member->update(['balance' => $balance]);
        $data = $this->postData();

        $response = $this->postWithIdentity('/transaction/event-bonus', $data);
        $betRecord = PgsswBetRecord::first();
        $eventRecord = PgsswEventRecord::first();
        $this->member->refresh();

        $platformAmount = Common::numDiv($data['amount'], $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balance, $platformAmount, 4);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => [
                'transactionNo' => $data['transactionNo'],
                'beforeAmount' => $this->expectedResponseAmount($balance),
                'afterAmount' => $this->expectedResponseAmount($expectedBalance),
            ],
        ]);

        $this->assertSame('0.0000', $betRecord->bet);
        $this->assertSame($platformAmount, $betRecord->win);
        $this->assertSame(1, TransactionLog::count());

        $this->assertNotNull($eventRecord);
        $this->assertSame($data['transactionNo'], $eventRecord->bet_id);
        $this->assertSame($this->playerUniqueId, $eventRecord->username);
    }

    #[TestDox('beforeAmount / afterAmount 帶三位以上小數：同樣截到小數兩位')]
    #[Group('rules/vendor-target#R-19')]
    public function test_event_bonus_truncates_amounts_to_two_decimals()
    {
        // 平台餘額 1.8495 + 派彩 0.5 = 2.3495，兩者回應時皆須捨去至兩位
        $this->member->update(['balance' => '1.8495']);

        $response = $this->postWithIdentity('/transaction/event-bonus', $this->postData(['amount' => 0.5]));

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => [
                'beforeAmount' => 1.84,
                'afterAmount' => 2.34,
            ],
        ]);
        $this->member->refresh();
        $this->assertSame('2.3495', $this->member->balance);
    }

    #[TestDox('重複 transactionNo：回傳 DUPLICATE_TRANSACTION，DB 僅一筆')]
    #[Group('rules/vendor-target#R-19')]
    public function test_event_bonus_returns_duplicate_transaction_for_same_id()
    {
        $this->member->update(['balance' => '1000.0000']);
        $data = $this->postData();

        $this->postWithIdentity('/transaction/event-bonus', $data);
        $response = $this->postWithIdentity('/transaction/event-bonus', $data);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value,
        ]);
        $this->assertSame(1, PgsswBetRecord::count());
    }

    #[TestDox('X-PROVIDER-IDENTITY 標頭錯誤：回傳 INVALID_SIGNATURE')]
    public function test_event_bonus_returns_error_when_identity_header_is_wrong()
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->withHeaders(['X-PROVIDER-IDENTITY' => 'wrong-identity'])
            ->postJson("{$this->baseUrl}/transaction/event-bonus", $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
        $this->assertSame(0, PgsswBetRecord::count());
        $this->assertSame(0, TransactionLog::count());
    }
}
