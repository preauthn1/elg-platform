<?php

declare(strict_types=1);

namespace Tests\Feature\External\CashFlow;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * DepositNotifyController 的回調請求 log 憑證遮罩。
 *
 * 重現事故：telco PIN 回調進入 deposit/notify，controller 在型別檢查前先記
 * 「Deposit callback received」（含原始 request），PIN 不可明文外洩。
 *
 * php artisan test tests/Feature/External/CashFlow/DepositNotifyCredentialMaskTest.php
 */
class DepositNotifyCredentialMaskTest extends TestCase
{
    public function test_deposit_callback_received_log_masks_pin(): void
    {
        $agent = $this->createAgentAndDomain();
        // cashFlow type=2（非線上代收）：會記下 line 48 的 callback log 後於型別檢查拋錯，
        // 正好覆蓋「記 log → 擋下」這段事故路徑，無需建立完整 provider。
        [, $agentCashFlow] = $this->createDefaultCashFlow($agent, cashFlowId: 777, type: 2);

        Log::spy();

        $this->postJson("external/api/deposit/notify/{$agentCashFlow->id}", [
            'pin' => '4830185194585221',
            'token' => 'sig-xyz',
            'amount' => '30.00',
        ]);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Deposit callback received')
                    && ($context['request']['pin'] ?? null) === '************5221'
                    && ($context['request']['token'] ?? null) === 'sig-xyz';
            })
            ->atLeast()->once();
    }
}
