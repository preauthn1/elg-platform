<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\RGSW;

use App\Helpers\Common;
use App\Vendors\RGSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * rate ≠ 1 的白標行為（target/op_tickets#3100 圓桌審查發現）
 *
 * 請求金額是遊戲商幣值，除以 rate 才是平台幣值，而 `Common::numDiv()` 預設留 10 位小數、
 * 注單金額欄位卻是 decimal(18,4)。拿 10 位小數的請求金額去比 4 位小數的注單金額，
 * 除不盡的 rate（例如 3）就會讓「全額退款」被判成超額而永遠退不掉。
 *
 * TPower 目前 rate = 1.0 所以踩不到，這組測試是為了下一個接 RG 的白標。
 */
class RateConversionTest extends RgswTestCase
{
    private const BET_ID = '7078921791';

    /** 除不盡的換算率：100 / 3 = 33.3333333333…，注單只留得住 33.3333 */
    protected float $rate = 3.0;

    #[Group('rules/vendor-rgsw#R-19')]
    public function test_full_refund_is_not_rejected_when_the_rate_does_not_divide_evenly(): void
    {
        // Arrange：遊戲商幣值 100 的下注，落到注單上是平台幣值 33.3333
        $this->setBalance('1000');
        $betRecord = $this->createPendingBetRecord(self::BET_ID, bet: '33.3333');

        // Act：把整筆退掉（遊戲商幣值 100）
        $response = $this->postWithToken(self::API_RGSW_REFUND, [
            'order_sn' => self::BET_ID,
            'amount' => '100',
        ]);

        // Assert：不可以被判成超額退款，而且要認得出這是全額退款
        $this->assertRgSuccess($response);
        $this->assertSame(0, Common::numCompare('1033.3333', $this->currentBalance()));

        $betRecord->refresh();
        $this->assertSame(BetRecordStatusEnum::REFUND, $betRecord->status, '退乾淨了就該關單，不能留下 0.0001 以下的殘量卡在 BET');
        $this->assertSame(0, Common::numCompare('0.0000', (string) $betRecord->valid_bet));
    }

    #[Group('rules/vendor-rgsw#R-24')]
    public function test_duplicate_response_returns_the_balance_in_vendor_currency(): void
    {
        // Arrange：平台幣值 1000，遊戲商看到的是 3000
        $this->setBalance('1000');
        $this->createSettledBetRecord(self::BET_ID, bet: '5', win: '10');

        $payload = [
            'username' => $this->playerCode,
            'bet_no' => self::BET_ID,
            'order_sn' => self::BET_ID,
            'amount' => '3',
        ];

        // Act：credit 遊戲商幣值 3（＝平台幣值 1），再重送一次
        $this->assertRgSuccess($this->postWithToken(self::API_RGSW_CREDIT, $payload));
        $second = $this->postWithToken(self::API_RGSW_CREDIT, $payload);

        // Assert：重送回應帶的是換算後的餘額，與正常回應同一套語彙
        $this->assertRgSuccess($second);
        $this->assertSame(0, Common::numCompare('1001.0000', $this->currentBalance()));
        // 用數值比較而非型別嚴格比對：JSON 會把 3003.0 序列化成 3003
        $second->assertJsonPath(
            'data.balance',
            static fn ($balance): bool => Common::numCompare('3003', (string) $balance, 4) === 0
        );
    }
}
