<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Concerns;

use App\Console\Commands\CashFlow\Concerns\MergesProviderInfoOverrides;
use Tests\TestCase;

/**
 * 驗證 provider 配置 info_overrides 的解析規則：
 * 依 cash_flow type（1=代收→deposit、3=代付→withdrawal）＋幣別取出覆寫值，
 * 無配置 / 幣別不匹配 / 配置非陣列時一律返回空陣列（no-op）。
 */
class MergesProviderInfoOverridesTest extends TestCase
{
    private object $subject;

    private const PROVIDER_CONFIG = [
        'credentials' => ['API_URL' => 'https://example.test', 'PROVIDER_ID' => 0],
        'deposit' => [
            'cash_flow_id' => 119,
            'info_overrides' => [
                'SGD' => ['VERSION' => 'V2'],
            ],
        ],
        'withdrawal' => [
            'cash_flow_id' => 120,
            'info_overrides' => [
                'MYR' => ['PROVIDER_ID' => 5, 'PROVIDER_TYPE' => 10],
                'SGD' => ['VERSION' => 'V2'],
                'THB' => 'not-an-array', // 配置錯誤防呆 case
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new class
        {
            use MergesProviderInfoOverrides;

            public function resolve(array $config, int $type, string $currency): array
            {
                return $this->resolveInfoOverrides($config, $type, $currency);
            }

            public function apply(array $credentials, array $config, int $type, string $currency): array
            {
                return $this->applyInfoOverrides($credentials, $config, $type, $currency);
            }

            /** 承接 trait 的輸出呼叫（實際命令由 Illuminate\Console\Command 提供） */
            public function line(string $string): void {}
        };
    }

    public function test_代付依幣別取得_withdrawal_區塊覆寫(): void
    {
        $this->assertSame(
            ['PROVIDER_ID' => 5, 'PROVIDER_TYPE' => 10],
            $this->subject->resolve(self::PROVIDER_CONFIG, 3, 'MYR'),
        );
    }

    public function test_代收依幣別取得_deposit_區塊覆寫(): void
    {
        $this->assertSame(
            ['VERSION' => 'V2'],
            $this->subject->resolve(self::PROVIDER_CONFIG, 1, 'SGD'),
        );
    }

    public function test_代收不吃代付的覆寫_同幣別分流(): void
    {
        // 代收 MYR 必須維持空（PROVIDER_ID=0 走 channel key 動態解析）
        $this->assertSame([], $this->subject->resolve(self::PROVIDER_CONFIG, 1, 'MYR'));
    }

    public function test_幣別無覆寫時返回空陣列(): void
    {
        $this->assertSame([], $this->subject->resolve(self::PROVIDER_CONFIG, 3, 'IDR'));
    }

    public function test_配置無_info_overrides_區塊時返回空陣列(): void
    {
        $this->assertSame([], $this->subject->resolve(['credentials' => []], 3, 'MYR'));
    }

    public function test_覆寫值非陣列時視為配置錯誤返回空陣列(): void
    {
        $this->assertSame([], $this->subject->resolve(self::PROVIDER_CONFIG, 3, 'THB'));
    }

    public function test_apply_將覆寫併入模板且覆寫值優先(): void
    {
        $this->assertSame(
            ['API_URL' => 'https://example.test', 'PROVIDER_ID' => 5, 'PROVIDER_TYPE' => 10],
            $this->subject->apply(
                ['API_URL' => 'https://example.test', 'PROVIDER_ID' => 0],
                self::PROVIDER_CONFIG,
                3,
                'MYR',
            ),
        );
    }

    public function test_apply_無覆寫時模板原樣返回(): void
    {
        $credentials = ['API_URL' => 'https://example.test', 'PROVIDER_ID' => 0];

        $this->assertSame($credentials, $this->subject->apply($credentials, self::PROVIDER_CONFIG, 1, 'MYR'));
    }
}
