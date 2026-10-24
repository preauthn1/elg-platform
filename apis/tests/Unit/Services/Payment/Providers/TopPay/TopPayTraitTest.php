<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TopPay;

use App\Services\Payment\Providers\TopPay\TopPayTrait;
use Tests\TestCase;

/**
 * TopPay Trait 測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TopPay/TopPayTraitTest.php
 */
class TopPayTraitTest extends TestCase
{
    private object $subject;

    private string $privateKey = 'MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDJq4sgb3F/WsmbuDKZw7xyJWwke9l/RFmPed+pgDLxnhdSHJ2b1zUgMCxqA/Pxs+owD2UvQheVCg1XSHXkBciaqv+w9daJZgf3BerqE3UDUIj4iFITbkREPM70zMKZMFEr4NI1p2VZk2HzXPoWSdU9EdIL5gC99Sifw3q9H9VflvDl0FXdht/QYJnKfW+9cRVLMLnZFDDRtgrhteMmpEv61W42fFagUpViLJfkVo4K7biqv34zmNtwV/njxy+HV36fFZH+ElpahYP+Q9fXn1PdO0QZLICij8xfYdv4VBnMjQIKlf7HwqUlU4/SFfpYp+imjts9r2oKGceXvX6t6+6nAgMBAAECggEALgSVXiTf7rIeQAjDjx/BgAB3EUorgqVrlFg4h7qHpWewJZ3+/uEYL8yeTeCyCQzHWwkgSyzXKbyd97VCpXIGaPitGMm4FykvlxgEFz6yXDvDzYZ5yBJIxJNlnCmfHAqQ8yS9gt28PDjryHcGYBCf9mv7FJj6wV3QU8hs2PwLGeD049qPJBBhJYOusm6U+aX6ZnDcjAYK4S1tZdB/nSGOsfc3zvsRs6Iwp3jP27lVCJLkyM6opzhOqDLP7G7tS0oz9cLY++HY4Xn0qKK30a34Nm5WK9qb0Tq9RyEbDqYMDfzsBlvdSxKzCRtsib28TAPu3zD6Iiu0ydqtGtxndE5jXQKBgQD4RDv67A4egE3aVPAZI5mBKSe/T6sZ4zuEckxNbV2yxnWza9yXkhLerSS4GtCIbzC+h5MHYsKu/Y0lBaElxMhbM/NfxwlF8YMNRU2juKl/OfoF+dZ7oOp4w6W1ZpySZiA27YpcjPxL8MJ5pzVzS+GsBlwkIlgAf3vanl36X0mGLQKBgQDP87uFzlxyKIGsOXg5XfQY7ovifuO8xdETUSyZ4Z8SAGUTE8YbH+IEynSLwFyf7x/FW3pLY1IzLznKyg3NiHec9guyuGdQJgcBpZpLbf3rxnMooqOvBcMJKW10Cym5O1IdJkY1/5QuOU8tcimqg+yk8cDwhSDoSVgkdqE0OzmAowKBgDEgD15PZ1vRt5YESJ4w1SchpaPNd+XfeN90XnTTtz6w1olyRmwXk3RMfn+PjsPwfIy29vj3Kd7GfS9eNczra1ngpb/F+qfEalIt7HcBC0kJh9e24CIGbV8IF7oVW9X8L1OvHTnDDAla0qCP3Zu64CTR/VmJEjDHCRLxU1c/k6ThAoGACD8rNaA8bE6M7e6NhQDj2EVFiOLtVyQlKXXzpdqbu1QGrh3/oj2+ceAxhQVI5xLNmL8K94hlzx/VU+R1baw2yiO7zQehGT2Y04jkuI8RHdON55OjmCJLXMamz45NxknC9OL6r9OF5dth93RqkJeQv+3Sb/dnma6m7zNBK1gZ7K8CgYBausfxv0LkKHs9wZJ3OoDHAM8Eibs55kVq4bDBeWIQXuRPJgglzUYSZ55JWH/bG22PFlrk4VWtPPS7ti3rrkyYqEGxdlAKydx/g2j0K4yal/zDJHE9ibVDmcvtItvTW4Ju47qvkqbIkZ6DeQo4GggoCET01Y+wO1VVPl/2uDfmbg==';

    private string $publicKey = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAyauLIG9xf1rJm7gymcO8ciVsJHvZf0RZj3nfqYAy8Z4XUhydm9c1IDAsagPz8bPqMA9lL0IXlQoNV0h15AXImqr/sPXWiWYH9wXq6hN1A1CI+IhSE25ERDzO9MzCmTBRK+DSNadlWZNh81z6FknVPRHSC+YAvfUon8N6vR/VX5bw5dBV3Ybf0GCZyn1vvXEVSzC52RQw0bYK4bXjJqRL+tVuNnxWoFKVYiyX5FaOCu24qr9+M5jbcFf548cvh1d+nxWR/hJaWoWD/kPX159T3TtEGSyAoo/MX2Hb+FQZzI0CCpX+x8KlJVOP0hX6WKfopo7bPa9qChnHl71+revupwIDAQAB';

    protected function setUp(): void
    {
        parent::setUp();

        $privateKey = $this->privateKey;
        $publicKey = $this->publicKey;

        $this->subject = new class($privateKey, $publicKey)
        {
            use TopPayTrait;

            protected array $info;

            public function __construct(string $privateKey, string $publicKey)
            {
                $this->info = [
                    'API_URL' => 'https://example.invalid/sandbox/id',
                    'MERCHANT_CODE' => 'SD123456',
                    'MERCHANT_PRIVATE_KEY' => $privateKey,
                    'PLATFORM_PUBLIC_KEY' => $publicKey,
                ];
            }

            public function getDriverName(): string
            {
                return 'TopPay';
            }

            public function publicGenerateSign(array $params): string
            {
                return $this->generateSign($params);
            }

            public function publicVerifySign(array $params): bool
            {
                return $this->verifySign($params);
            }
        };
    }

    /**
     * 測試簽名生成並自簽自驗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/TopPayTraitTest.php --filter test_sign_and_verify_roundtrip
     */
    public function test_sign_and_verify_roundtrip(): void
    {
        $params = [
            'mchNo' => 'SD123456',
            'orderNum' => 'ID20251030001',
            'amount' => 100000,
            'timestamp' => '1749451858772',
        ];

        $sign = $this->subject->publicGenerateSign($params);
        $this->assertNotEmpty($sign);

        $params['sign'] = $sign;
        $this->assertTrue($this->subject->publicVerifySign($params));
    }

    /**
     * 測試簽名時自動過濾 null / 空字串欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/TopPayTraitTest.php --filter test_sign_excludes_null_and_empty
     */
    public function test_sign_excludes_null_and_empty(): void
    {
        $paramsWithNull = [
            'mchNo' => 'SD123456',
            'orderNum' => 'ID20251030001',
            'amount' => 100000,
            'timestamp' => '1749451858772',
            'method' => null,
            'redirectUrl' => '',
        ];

        $paramsWithout = [
            'mchNo' => 'SD123456',
            'orderNum' => 'ID20251030001',
            'amount' => 100000,
            'timestamp' => '1749451858772',
        ];

        $this->assertEquals(
            $this->subject->publicGenerateSign($paramsWithout),
            $this->subject->publicGenerateSign($paramsWithNull),
        );
    }

    /**
     * 測試驗章失敗（簽名被篡改）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/TopPayTraitTest.php --filter test_verify_fails_with_tampered_sign
     */
    public function test_verify_fails_with_tampered_sign(): void
    {
        $params = [
            'mchNo' => 'SD123456',
            'orderNum' => 'ID20251030001',
            'amount' => 100000,
            'timestamp' => '1749451858772',
            'sign' => 'invalid_sign_value',
        ];

        $this->assertFalse($this->subject->publicVerifySign($params));
    }

    /**
     * 測試驗章失敗（sign 欄位缺失）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TopPay/TopPayTraitTest.php --filter test_verify_fails_with_missing_sign
     */
    public function test_verify_fails_with_missing_sign(): void
    {
        $params = [
            'mchNo' => 'SD123456',
            'orderNum' => 'ID20251030001',
            'amount' => 100000,
            'timestamp' => '1749451858772',
        ];

        $this->assertFalse($this->subject->publicVerifySign($params));
    }
}
