<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Currency;

use App\Enums\LanguageEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Currency;
use Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Currency/ListTest.php
 */
class ListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        // 幣別清單需完全受控；用 DELETE（DML、可被 DatabaseTransactions 回滾）而非 TRUNCATE——
        // TRUNCATE 是 DDL 隱式 commit，會打破測試交易，且連錯庫時等於清空真實 currencies 主檔
        DB::table('currencies')->delete();

        $currency = Currency::create(
            [
                'code' => 'MYR',
                'name' => 'Malaysia Dollar',
                'rate' => '1',
                'status' => '1',
                'admin' => '1',
            ]
        );
        $currency->names()->create([
            'name' => '馬幣',
            'language_id' => LanguageEnum::ZH_TW->value,
        ]);

        $currency = Currency::create(
            [
                'code' => 'SGD',
                'name' => 'Singapore Dollar',
                'rate' => '1',
                'status' => '1',
                'admin' => '1',
            ]
        );
        $currency = Currency::create(
            [
                'code' => 'VND',
                'name' => 'VND',
                'rate' => '1',
                'status' => '0',
                'admin' => '1',
            ]
        );
    }

    public function test_success()
    {
        // Act
        $response = $this->json(
            method: 'POST',
            uri: 'frontend/api/currency/list',
            data: [],
            headers: [
                'Accept-Language' => 'zh-TW',
            ]
        );

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'name',
                        'rate',
                    ],
                ],
            ]);

        $data = $response->json()['data'];
        // 檢查 status, 正常來說只有2筆
        $this->assertCount(2, $data);

        // 檢查有多語言
        $this->assertEquals('馬幣', $data[0]['name']);

        // 檢查沒有多語言
        $this->assertEquals('Singapore Dollar', $data[1]['name']);
    }
}
