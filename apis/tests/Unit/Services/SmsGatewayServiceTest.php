<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\SmsGatewayInfoData;
use App\Data\SmsResultData;
use App\Models\SmsGateway;
use App\Models\SmsGatewayInfo;
use App\Models\SmsSendRecord;
use App\Services\SmsGatewayService;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * SmsGatewayService 測試
 *
 * php artisan test tests/Unit/Services/SmsGatewayServiceTest.php
 */
class SmsGatewayServiceTest extends TestCase
{
    private SmsGatewayService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'sms-' . uniqid('', true) . '.local';
        $this->service = new SmsGatewayService;
    }

    public function test_send_sms_throws_when_gateway_class_missing(): void
    {
        $gw = SmsGateway::create([
            'name' => 'Missing',
            'code' => 'missingxyz',
            'info' => '{}',
            'url' => 'http://localhost',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);
        $info = SmsGatewayInfo::create([
            'sms_gateway_group_id' => 0,
            'sms_gateway_id' => $gw->id,
            'info' => '{}',
            'sort' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
        $data = SmsGatewayInfoData::from([
            'smsGatewayInfo' => $info->load('smsGateway'),
            'countryId' => 1,
            'countryCode' => 1,
        ]);
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sms_m1_' . uniqid()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported SMS Gateway');

        $this->service->sendSms($data, $member, '0912000000', 'hi', 2);
    }

    public function test_send_sms_uses_local_gateway_and_updates_record(): void
    {
        $gw = SmsGateway::create([
            'name' => 'LocalTest',
            'code' => 'local',
            'info' => '{}',
            'url' => 'http://localhost',
            'memo' => '',
            'status' => 1,
            'admin' => 0,
        ]);
        $info = SmsGatewayInfo::create([
            'sms_gateway_group_id' => 0,
            'sms_gateway_id' => $gw->id,
            'info' => '{}',
            'sort' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
        $data = SmsGatewayInfoData::from([
            'smsGatewayInfo' => $info->load('smsGateway'),
            'countryId' => 1,
            'countryCode' => 1,
        ]);
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sms_m2_' . uniqid()]);

        $before = SmsSendRecord::query()->count();
        $result = $this->service->sendSms($data, $member, '0912000000', 'hello', 2);

        $this->assertInstanceOf(SmsResultData::class, $result);
        $this->assertSame($before + 1, SmsSendRecord::query()->count());
        $record = SmsSendRecord::query()->latest('id')->first();
        $this->assertSame(1, $record->status);
        $this->assertNotEmpty($record->transaction_id);
    }
}
