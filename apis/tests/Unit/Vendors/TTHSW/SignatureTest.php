<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\TTHSW;

use App\Vendors\TTHSW\Helpers\SignatureHelper;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    /**
     * 三方以 C# HttpUtility.UrlEncode 驗簽，!*() 不編碼、空白編為 +。
     *
     * 體育投注的 Selection／Match 常帶括號與空白，PHP urlencode 會多編成 %28 %29 造成驗簽失敗。
     */
    public function test_值以_dot_net_規則編碼(): void
    {
        $cases = [
            'Xelaju MC (N)' => 'Xelaju+MC+(N)',
            'a!b*c(d)e' => 'a!b*c(d)e',
            'A-B_C.D' => 'A-B_C.D',
            '2026-08-19T04:05:57.284+00:00' => '2026-08-19T04%3a05%3a57.284%2b00%3a00',
            "Nott'm Forest" => 'Nott%27m+Forest',
            'a~b' => 'a%7eb',
            'a&b=c' => 'a%26b%3dc',
            '中文' => '%e4%b8%ad%e6%96%87',
        ];

        foreach ($cases as $raw => $expected) {
            $this->assertSame(
                'K=' . $expected,
                SignatureHelper::toSortedQueryString(['K' => $raw]),
                "值 [{$raw}] 編碼結果不符",
            );
        }
    }

    public function test_巢狀陣列展開為_key_索引_點_子鍵(): void
    {
        $query = SignatureHelper::toSortedQueryString([
            'GameRoundInfo' => [
                ['Match' => 'A vs B', 'Odds' => '-129.0'],
                ['Match' => 'C vs D'],
            ],
        ]);

        $this->assertSame(
            'GameRoundInfo%5b0%5d.Match=A+vs+B'
            . '&GameRoundInfo%5b0%5d.Odds=-129.0'
            . '&GameRoundInfo%5b1%5d.Match=C+vs+D',
            $query,
        );
    }

    public function test_空值與_null_排除於簽名之外(): void
    {
        $query = SignatureHelper::toSortedQueryString([
            'A' => 'a',
            'B' => '',
            'C' => null,
            'D' => [],
            'E' => 0,
            'F' => false,
        ]);

        $this->assertSame('A=a&E=0&F=false', $query);
    }

    public function test_參數依鍵名排序(): void
    {
        $this->assertSame(
            'Amount=1&Currency=MYR&Type=1',
            SignatureHelper::toSortedQueryString(['Type' => 1, 'Amount' => 1, 'Currency' => 'MYR']),
        );
    }

    /**
     * 以 2026-08-19 IBC deduct 實際回調的 payload 為準（金鑰替換為測試值）
     */
    public function test_deduct_回調可產出正確簽名字串(): void
    {
        $payload = [
            'ProviderCode' => 'IBC',
            'PlayerCode' => 'ibc00000001',
            'GameRoundId' => '4377300_51_U',
            'TransactionId' => '4377300_1_126_U',
            'TransactionDate' => '2026-08-19T04:05:57.284+00:00',
            'Currency' => 'MYR',
            'Amount' => '1.29',
            'GameCode' => 'IBC_100',
            'GameType' => 3,
            'GameCategory' => 99,
            'Type' => 1,
            'ReserveBetRoundingTolerance' => '0',
            'PromotionEventType' => 0,
            'PromotionEventRefNo' => '',
            'PromotionEventName' => '',
            'GameRoundInfo' => [
                [
                    'Match' => 'Soccer - CONCACAF CENTRAL AMERICAN CUP - Xelaju MC vs Diriangen FC',
                    'BetType' => 'Handicap -0.25',
                    'Selection' => 'Xelaju MC (N)',
                    'OddsType' => 'American',
                    'Odds' => '-129.0',
                    'BetPerLine' => null,
                    'Lines' => null,
                ],
            ],
        ];

        $expectedQuery = 'Amount=1.29'
            . '&Currency=MYR'
            . '&GameCategory=99'
            . '&GameCode=IBC_100'
            . '&GameRoundId=4377300_51_U'
            . '&GameRoundInfo%5b0%5d.BetType=Handicap+-0.25'
            . '&GameRoundInfo%5b0%5d.Match=Soccer+-+CONCACAF+CENTRAL+AMERICAN+CUP+-+Xelaju+MC+vs+Diriangen+FC'
            . '&GameRoundInfo%5b0%5d.Odds=-129.0'
            . '&GameRoundInfo%5b0%5d.OddsType=American'
            . '&GameRoundInfo%5b0%5d.Selection=Xelaju+MC+(N)'
            . '&GameType=3'
            . '&PlayerCode=ibc00000001'
            . '&PromotionEventType=0'
            . '&ProviderCode=IBC'
            . '&ReserveBetRoundingTolerance=0'
            . '&TransactionDate=2026-08-19T04%3a05%3a57.284%2b00%3a00'
            . '&TransactionId=4377300_1_126_U'
            . '&Type=1';

        $this->assertSame($expectedQuery, SignatureHelper::toSortedQueryString($payload));

        $secretKey = 'unit-test-secret-key';
        $timestamp = 1787112357290;

        $this->assertSame(
            base64_encode(hash_hmac('sha256', $expectedQuery . '|' . $timestamp, $secretKey, true)),
            SignatureHelper::generateSignature($payload, $secretKey, $timestamp),
        );

        $this->assertTrue(SignatureHelper::verifySignature(
            $payload,
            $secretKey,
            SignatureHelper::generateSignature($payload, $secretKey, $timestamp),
            $timestamp,
        ));
    }
}
