<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\External\MotivationSW;

use App\Http\Requests\External\MotivationSW\RollinRequest;
use Illuminate\Routing\Redirector;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 魚機／街機的 rollin 回調不帶桌號（tableid 為 null 或整個沒送），驗證必須放行。
 * 2025-08-08 起 tableid 被設成 required，魚機轉回全被 400 擋下，轉出的額度回不了錢包。
 *
 * php artisan test tests/Unit/Http/Requests/External/MotivationSW/RollinRequestTableIdTest.php
 */
class RollinRequestTableIdTest extends TestCase
{
    #[TestDox('魚機轉回 tableid 為 null 時通過驗證')]
    public function test_fish_rollin_with_null_tableid_passes(): void
    {
        $request = $this->makeRequest(['tableid' => null, 'gametype' => 'fish']);

        $request->validateResolved();

        $this->assertNull($request->input('tableid'));
    }

    #[TestDox('轉回沒送 tableid 欄位時通過驗證')]
    public function test_rollin_without_tableid_field_passes(): void
    {
        $request = $this->makeRequest(['gametype' => 'arcade']);

        $request->validateResolved();

        $this->assertNull($request->input('tableid'));
    }

    #[TestDox('牌桌轉回帶桌號時照常通過驗證')]
    public function test_table_rollin_with_tableid_passes(): void
    {
        $request = $this->makeRequest(['tableid' => 'T01', 'gametype' => 'table']);

        $request->validateResolved();

        $this->assertSame('T01', $request->input('tableid'));
    }

    #[TestDox('tableid 超過 36 字仍擋下')]
    public function test_tableid_longer_than_36_fails(): void
    {
        $request = $this->makeRequest(['tableid' => str_repeat('a', 37), 'gametype' => 'table']);

        $this->expectException(ValidationException::class);

        $request->validateResolved();
    }

    /**
     * 照廠商實際送來的魚機轉回內容建 request，$overrides 覆蓋個別欄位。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeRequest(array $overrides): RollinRequest
    {
        $payload = [
            'account' => 'devMotivSW00023679',
            'eventTime' => '2026-10-12T22:13:56.76-04:00',
            'gamehall' => 'cq9',
            'gamecode' => 'AT05',
            'roundid' => 'AT05m8946489m1m1',
            'validbet' => '0',
            'bet' => '97.000000',
            'win' => '36.000000',
            'roomfee' => '0',
            'amount' => '149939.000000',
            'mtcode' => 'pro-rollin-AT05m8946489m1m1:cq9',
            'createTime' => '2026-10-12T22:13:56.76-04:00',
            'rake' => '0',
            'gametype' => 'fish',
        ];

        $request = RollinRequest::create('/external/api/sw/motivation/transaction/game/rollin', 'POST', array_merge($payload, $overrides));
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(Redirector::class));

        return $request;
    }
}
