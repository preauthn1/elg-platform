<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Country;

use App\Models\Agent;
use App\Models\Country;
use App\Models\Member;
use App\Models\SmsGateway;
use App\Models\SmsGatewayInfo;
use Database\Seeders\CountrySeeder;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Country/CountryListTest.php
 */
class CountryListTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken;

    private int $countryId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CountrySeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->sms_gateway_group_id = 1;
        $this->agent->save();

        // sms_gateway_id 用真實建立的 row，不可沿用寫死的數字：AUTO_INCREMENT 跨交易不回捲，
        // 寫死值可能撞到本測試（或平行執行的其他測試）新建的 SmsGateway 真實 id，讓 has_whatsapp 誤判
        $smsGateway = SmsGateway::create([
            'name' => 'SmsTest',
            'code' => 'growixv2',
            'info' => '{}',
            'url' => 'https://api.otp.expert',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $smsGatewayInfo = SmsGatewayInfo::create([
            'sms_gateway_group_id' => $this->agent->sms_gateway_group_id,
            'sms_gateway_id' => $smsGateway->id,
            'cost' => 0.8,
            'status' => 1,
        ]);

        $smsGatewayInfo->smsGatewayInfoCountries()->create([
            'country_id' => $this->countryId,
            'status' => 1,
        ]);
    }

    public function test_country_list_success_without_redis()
    {
        // Arrange
        $relationedCountry = Country::find($this->countryId);

        // Disable redis for clean test.
        Redis::shouldReceive('exists')->andReturn(false);
        Redis::shouldReceive('set')->once();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $datas = $response->json('data');
        $this->assertEquals(1, count($datas));
        $this->assertEquals($this->countryId, $datas[0]['id']);
        $this->assertEquals($relationedCountry->code, $datas[0]['code']);
        $this->assertEquals($relationedCountry->name, $datas[0]['name']);
        $this->assertEquals($relationedCountry->country_code, $datas[0]['country_code']);
        $this->assertEquals($relationedCountry->phone_rule, $datas[0]['phone_rule']);
        $this->assertFalse($datas[0]['has_whatsapp']);
        $this->assertTrue($datas[0]['has_sms']);
    }

    public function test_country_list_marks_has_whatsapp_when_country_has_whatsapp_gateway()
    {
        // Arrange：另一個國家只掛 WhatsApp 管道的簡訊商（code=wa）
        $whatsAppCountryId = 2;
        $whatsAppGateway = SmsGateway::create([
            'name' => 'WhatsAppTest',
            'code' => 'wa',
            'info' => '{}',
            'url' => 'https://verifynow.asia',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $whatsAppGatewayInfo = SmsGatewayInfo::create([
            'sms_gateway_group_id' => $this->agent->sms_gateway_group_id,
            'sms_gateway_id' => $whatsAppGateway->id,
            'cost' => 0.8,
            'status' => 1,
        ]);

        $whatsAppGatewayInfo->smsGatewayInfoCountries()->create([
            'country_id' => $whatsAppCountryId,
            'status' => 1,
        ]);

        Redis::shouldReceive('exists')->andReturn(false);
        Redis::shouldReceive('set')->once();

        // Act
        $response = $this->act(['domain' => $this->domain]);

        // Assert
        $datas = collect($response->json('data'))->keyBy('id');
        $this->assertFalse($datas[$this->countryId]['has_whatsapp']);
        $this->assertTrue($datas[$this->countryId]['has_sms']);
        $this->assertTrue($datas[$whatsAppCountryId]['has_whatsapp']);
        $this->assertFalse($datas[$whatsAppCountryId]['has_sms']);
    }

    public function test_country_list_success_with_redis()
    {
        // Arrange

        // Act
        $response1 = $this->act(['domain' => $this->domain]);
        $response2 = $this->act(['domain' => $this->domain]);

        // Assert
        $response1->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $response2->assertStatus(200)
            ->assertJson([
                'code' => 200,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $datas1 = $response1->json('data');
        $datas2 = $response2->json('data');
        $this->assertEquals($datas1, $datas2);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/country/list',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                [
                    'id',
                    'code',
                    'name',
                    'country_code',
                    'phone_rule',
                    'has_whatsapp',
                    'has_sms',
                ],
            ],
        ];
    }
}
