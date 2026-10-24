<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\CashFlow\Concerns;

use App\Console\Commands\CashFlow\Concerns\ResolvesProviderConfigFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * provider 配置檔定位邏輯測試
 *
 * php artisan test tests/Unit/Console/Commands/CashFlow/Concerns/ResolvesProviderConfigFileTest.php
 */
class ResolvesProviderConfigFileTest extends TestCase
{
    /** 暴露 trait 的 protected 方法供測試呼叫 */
    private object $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new class
        {
            use ResolvesProviderConfigFile;

            /** @param  array<int, string>  $files */
            public function resolve(string $thirdPartyCode, array $files): ?string
            {
                return $this->resolveProviderFileName($thirdPartyCode, $files);
            }
        };
    }

    /**
     * 同一 Driver 供多商戶共用時，檔名精確命中必須優先於 config 反解
     *
     * SPEEDPAY 與 PAYMATE 都註冊到 PayMate\Driver，若先走 config 反解，兩者都會指向
     * PayMate.php 而讀到彼此的配置（cash_flow_id 對不上）。
     */
    public function test_file_name_exact_match_wins_over_config_lookup(): void
    {
        Config::set('payment.provider.SPEEDPAY', 'App\Services\Payment\Providers\PayMate\Driver');

        $files = ['PayMate', 'SpeedPay', 'DgPay'];

        $this->assertSame('SpeedPay', $this->resolver->resolve('D_SPEEDPAY', $files));
        $this->assertSame('SpeedPay', $this->resolver->resolve('W_SPEEDPAY', $files));
        $this->assertSame('PayMate', $this->resolver->resolve('D_PAYMATE', $files));
    }

    /**
     * 檔名無命中時，回退 config/payment.php 反解 namespace（既有行為）
     */
    public function test_falls_back_to_config_lookup_when_no_file_name_match(): void
    {
        Config::set('payment.provider.DG', 'App\Services\Payment\Providers\DgPay\Driver');

        $this->assertSame('DgPay', $this->resolver->resolve('W_DG', ['PayMate', 'DgPay']));
    }

    /**
     * 檔名比對為大小寫不敏感
     */
    public function test_file_name_match_is_case_insensitive(): void
    {
        $this->assertSame('DgPay', $this->resolver->resolve('D_DGPAY', ['DgPay']));
    }

    /**
     * config 反解出的目錄名不存在於檔案清單時回傳 null（不得回傳未存在的檔名）
     */
    public function test_returns_null_when_config_resolved_name_has_no_file(): void
    {
        Config::set('payment.provider.GHOST', 'App\Services\Payment\Providers\GhostPay\Driver');

        $this->assertNull($this->resolver->resolve('D_GHOST', ['PayMate', 'DgPay']));
    }

    /**
     * 檔名與 config 皆無命中時回傳 null
     */
    public function test_returns_null_when_nothing_matches(): void
    {
        $this->assertNull($this->resolver->resolve('D_UNKNOWNPROVIDER', ['PayMate', 'DgPay']));
    }

    /**
     * 無 D_/W_ 前綴的 code 亦須正確解析
     */
    public function test_resolves_code_without_type_prefix(): void
    {
        $this->assertSame('SpeedPay', $this->resolver->resolve('SPEEDPAY', ['PayMate', 'SpeedPay']));
    }
}
