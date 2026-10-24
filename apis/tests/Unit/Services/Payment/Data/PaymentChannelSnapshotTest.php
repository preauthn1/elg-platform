<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Data;

use App\Enums\Payment\PaymentChannelDirection;
use App\Services\Payment\Data\PaymentChannel;
use App\Services\Payment\Data\PaymentChannelSnapshot;
use Tests\TestCase;

/**
 * 通道快照 DTO：商戶層欄位全 nullable（三方沒回就是 null，不偽造）；
 * 通道層帶方向 Enum 與正規化限額。
 */
class PaymentChannelSnapshotTest extends TestCase
{
    public function test_snapshot_holds_nullable_merchant_fields_and_channels(): void
    {
        $channel = new PaymentChannel(
            name: '10022198',
            direction: PaymentChannelDirection::Deposit,
            status: 'ON',
            minAmount: '10.0000',
            maxAmount: '1000.0000',
            supportBankCodes: ['MAYBANK', 'CIMB'],
        );

        $snapshot = new PaymentChannelSnapshot(
            merchantStatus: null,
            depositStatus: null,
            payoutStatus: null,
            availableBalance: null,
            totalBalance: null,
            channels: [$channel],
            raw: ['masked' => true],
        );

        $this->assertNull($snapshot->merchantStatus, 'SurePay 無商戶層欄位，null 不偽造');
        $this->assertSame('10022198', $snapshot->channels[0]->name);
        $this->assertSame(PaymentChannelDirection::Deposit, $snapshot->channels[0]->direction);
        $this->assertSame(['MAYBANK', 'CIMB'], $snapshot->channels[0]->supportBankCodes);
        $this->assertSame(['masked' => true], $snapshot->raw);
    }
}
