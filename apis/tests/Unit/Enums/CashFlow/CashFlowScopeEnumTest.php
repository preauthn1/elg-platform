<?php

declare(strict_types=1);

namespace Tests\Unit\Enums\CashFlow;

use App\Enums\CashFlow\CashFlowScopeEnum;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 新舊系統分界與承接名單 target/tickets#4433。
 *
 * 規則：cash_flow_id 大於 62 由 APIs 承接；62 以下只有 APIS_TAKEOVER_CASH_FLOW_IDS 名單內的才是。
 * 代理端建單與代付入口用 isHandledByApis() 擋下舊系統範疇的通道。
 *
 * php artisan test tests/Unit/Enums/CashFlow/CashFlowScopeEnumTest.php
 */
class CashFlowScopeEnumTest extends TestCase
{
    #[TestDox('分界以上一律由 APIs 承接')]
    public function testAboveBoundaryIsHandledByApis(): void
    {
        $this->assertTrue(CashFlowScopeEnum::isHandledByApis(63));
        $this->assertTrue(CashFlowScopeEnum::isHandledByApis(74));
    }

    #[TestDox('分界本身與以下、不在承接名單的通道不由 APIs 承接')]
    public function testBoundaryAndBelowWithoutTakeoverIsNotHandled(): void
    {
        $this->assertFalse(CashFlowScopeEnum::isHandledByApis(62));
        $this->assertFalse(CashFlowScopeEnum::isHandledByApis(39));
        $this->assertFalse(CashFlowScopeEnum::isHandledByApis(0));
    }

    #[TestDox('承接名單內的通道（58 TNGPIN 代收）即使在分界以下也由 APIs 承接')]
    public function testTakeoverListIsHandledByApis(): void
    {
        foreach (CashFlowScopeEnum::APIS_TAKEOVER_CASH_FLOW_IDS as $cashFlowId) {
            $this->assertLessThanOrEqual(CashFlowScopeEnum::LEGACY_BOUNDARY_CASH_FLOW_ID, $cashFlowId);
            $this->assertTrue(CashFlowScopeEnum::isHandledByApis($cashFlowId));
        }
        $this->assertContains(58, CashFlowScopeEnum::APIS_TAKEOVER_CASH_FLOW_IDS);
    }

    #[TestDox('不受理訊息帶出 cash_flow_id，前端能直接顯示')]
    public function testNotHandledMessageMentionsCashFlowId(): void
    {
        $this->assertSame(
            '此支付通道（cash_flow_id 39）過舊，APIs 無法支援',
            CashFlowScopeEnum::notHandledMessage(39),
        );
    }
}
