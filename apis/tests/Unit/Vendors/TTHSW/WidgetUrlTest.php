<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\TTHSW;

use App\Data\VendorGameUrlParamsData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Exceptions\ApiException;
use App\Vendors\Models\TTHSWAccount;
use App\Vendors\TTHSW\Driver;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ReflectionClass;

/**
 * TTHSW 1.5 Widget。不連資料庫：Driver 略過建構子、帳號換成不落地的替身。
 */
class WidgetUrlTest extends TestCase
{
    private const API_URL = 'https://tthsw.test';

    public function test_IBC_回_EMBED_時_pc_與_mobile_都是_EmbedScriptUrl(): void
    {
        Http::fake([
            self::API_URL . '/api/sm/services/widget' => Http::response([
                'Code' => 0,
                'Value' => [
                    'WidgetType' => 1,
                    'EmbedScriptUrl' => 'https://ibc.test/quick-bet/?token=[REDACTED]',
                    'EmbedToken' => null,
                    'RedirectUrl' => null,
                    'Matches' => null,
                ],
            ]),
        ]);
        [$driver, $account] = $this->makeDriver('IBC');

        $result = $driver->getWidgetUrl($this->params('https://example.invalid/', '{"Type":3}'));

        $this->assertSame('https://ibc.test/quick-bet/?token=[REDACTED]', $result->pc);
        $this->assertSame('https://ibc.test/quick-bet/?token=[REDACTED]', $result->mobile);

        Http::assertSent(function (Request $request) use ($account): bool {
            return $request['SiteCode'] === 'EH007'
                && $request['ProviderCode'] === 'IBC'
                && $request['AuthToken'] === $account->updates[0]['auth_token']
                && $request['GameType'] === 3
                && $request['Domain'] === 'example.invalid'
                && $request->hasHeader('X-Sign')
                && $request->hasHeader('X-Ts');
        });
    }

    public function test_REDIRECT_SINGLE_用_RedirectUrl(): void
    {
        Http::fake([
            '*' => Http::response(['Code' => 0, 'Value' => ['WidgetType' => 2, 'RedirectUrl' => 'https://ibc.test/hosted']]),
        ]);
        [$driver] = $this->makeDriver('IBC');

        $result = $driver->getWidgetUrl($this->params('https://example.invalid/'));

        $this->assertSame('https://ibc.test/hosted', $result->pc);
    }

    public function test_沒有遊戲資訊時_GameType_預設_3(): void
    {
        Http::fake(['*' => Http::response(['Code' => 0, 'Value' => ['WidgetType' => 1, 'EmbedScriptUrl' => 'https://x']])]);
        [$driver] = $this->makeDriver('IBC');

        $driver->getWidgetUrl($this->params('https://example.invalid/'));

        Http::assertSent(fn (Request $request): bool => $request['GameType'] === 3);
    }

    public function test_不寫死遊戲商_其他子遊戲商照樣送自己的_ProviderCode(): void
    {
        Http::fake(['*' => Http::response(['Code' => 0, 'Value' => ['WidgetType' => 2, 'RedirectUrl' => 'https://wbet.test/widget']])]);
        [$driver] = $this->makeDriver('WBET');

        $result = $driver->getWidgetUrl($this->params('https://example.invalid/'));

        $this->assertSame('https://wbet.test/widget', $result->pc);
        Http::assertSent(fn (Request $request): bool => $request['ProviderCode'] === 'WBET');
    }

    public function test_三方回錯誤碼時拋例外(): void
    {
        Http::fake(['*' => Http::response(['Code' => 907, 'Message' => 'Invalid sign'])]);
        [$driver] = $this->makeDriver('IBC');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid sign');

        $driver->getWidgetUrl($this->params('https://example.invalid/'));
    }

    public function test_REDIRECT_MATCHES_不支援(): void
    {
        Http::fake(['*' => Http::response(['Code' => 0, 'Value' => ['WidgetType' => 3, 'Matches' => []]])]);
        [$driver] = $this->makeDriver('IBC');

        $this->expectException(ApiException::class);

        $driver->getWidgetUrl($this->params('https://example.invalid/'));
    }

    /**
     * @return array{0: Driver, 1: TTHSWAccount&object{updates: array}}
     */
    private function makeDriver(string $provider): array
    {
        $driver = (new ReflectionClass(Driver::class))->newInstanceWithoutConstructor();
        $driver->setInfo([
            'API_URL' => self::API_URL,
            'API_KEY' => 'test-key',
            'SITE_CODE' => 'EH007',
            'PROVIDER' => $provider,
        ]);

        $account = new class extends TTHSWAccount
        {
            public array $updates = [];

            public function update(array $attributes = [], array $options = []): bool
            {
                $this->updates[] = $attributes;

                return true;
            }
        };
        $driver->setVendorAccount($account);

        return [$driver, $account];
    }

    private function params(string $exitUrl, ?string $info = null): VendorGameUrlParamsData
    {
        return VendorGameUrlParamsData::from([
            'gameCode' => 'IBC',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::from(1),
            'exitUrl' => $exitUrl,
            'info' => $info,
        ]);
    }
}
