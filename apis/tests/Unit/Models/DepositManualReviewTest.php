<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\DepositManualReviewReasonEnum;
use App\Enums\DepositManualReviewResultEnum;
use App\Models\DepositManualReview;
use App\Models\Order;
use LogicException;
use Tests\TestCase;

/**
 * 存款人工確認紀錄的寫入規則：allow_credit 由 result 決定、不准刪列（見 DepositManualReview 的 class docblock）。
 *
 * php artisan test tests/Unit/Models/DepositManualReviewTest.php
 */
class DepositManualReviewTest extends TestCase
{
    public function test_result_decides_allow_credit_and_rows_cannot_be_deleted(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'dmr_' . uniqid()]);
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, 129, 1);
        $order = Order::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'third_party_order_no' => 'dmr_record',
            'order_no' => 'dmr_' . uniqid(),
            'status' => 4,
            'balance' => 0,
            'amount' => '1',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $review = DepositManualReview::createPending($order, $agentCashFlow, DepositManualReviewReasonEnum::RISKY_TRANSACTION);
        $this->assertSame(0, (int) $review->fresh()->allow_credit);
        $this->assertNull($review->fresh()->resolved_at);

        $expected = [
            DepositManualReviewResultEnum::REFUNDED_TO_PAYER->value => 0,
            DepositManualReviewResultEnum::REFUNDED_TO_MERCHANT->value => 1,
        ];
        foreach ($expected as $result => $allowCredit) {
            $review->resolve(DepositManualReviewResultEnum::from($result), ['refund_record_id' => 'r']);
            $this->assertSame($allowCredit, (int) $review->fresh()->allow_credit, $result);
            $this->assertNotNull($review->fresh()->resolved_at, $result);
        }

        $thrown = false;
        try {
            $review->delete();
        } catch (LogicException) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'delete() 必須拋錯');
        $this->assertTrue(DepositManualReview::query()->whereKey($review->id)->exists(), '刪列會讓舊代理後台的鎖失效');
    }
}
