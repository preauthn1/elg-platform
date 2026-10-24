<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Exceptions\ApiException;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Providers\CCPayment\CCPaymentTrait;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 共用 trait 測試：回調驗簽與四個端點的請求形狀。
 *
 * 簽名字串一律手寫組成（APP ID＋Timestamp＋原始 body），不呼叫被測方法重建，
 * 避免測試鏡像實作缺陷。規格見 docs/integrations/payments/CCPayment/api.md 的
 * 「Webhook 驗簽」與「永久地址 API」兩節。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/CCPaymentTraitTest.php
 */
class CCPaymentTraitTest extends TestCase
{
    private const APP_ID = 'test_app_id';

    private const APP_SECRET = [REDACTED]';

    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private function createTestClass(): object
    {
        $subject = new class extends AbstractMethod
        {
            use CCPaymentTrait;

            public function callVerifyWebhookSignature(string $rawBody, ?string $timestamp, ?string $sign): bool
            {
                return $this->verifyWebhookSignature($rawBody, $timestamp, $sign);
            }

            public function callGetOrCreateDepositAddress(string $referenceId, string $chain, string $notifyUrl): array
            {
                return $this->getOrCreateDepositAddress($referenceId, $chain, $notifyUrl);
            }

            public function callUnbindAddress(string $chain, string $address): array
            {
                return $this->unbindAddress($chain, $address);
            }

            public function callListDepositRecords(array $params): array
            {
                return $this->listDepositRecords($params);
            }

            public function callResendWebhooks(array $params): array
            {
                return $this->resendWebhooks($params);
            }
        };

        $subject->setInfo([
            'API_URL' => self::API_URL,
            'APP_ID' => self::APP_ID,
            'APP_SECRET' => self::APP_SECRET,
        ]);

        return $subject;
    }

    /** 依廠商規則手工組簽名：HMAC-SHA256(APP ID + Timestamp + 原始 body, APP_SECRET)，hex */
    private function providerSign(string $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', self::APP_ID . $timestamp . $rawBody, self::APP_SECRET);
    }

    public function test_verify_webhook_signature_accepts_hmac_over_app_id_timestamp_and_raw_body(): void
    {
        $rawBody = '{"type":"DirectDeposit","msg":{"recordId":"2024031311310811","referenceId":"12-34"}}';
        $timestamp = '1741783734';

        $this->assertTrue($this->createTestClass()->callVerifyWebhookSignature(
            $rawBody,
            $timestamp,
            $this->providerSign($timestamp, $rawBody)
        ));
    }

    public function test_verify_webhook_signature_rejects_tampered_body(): void
    {
        $timestamp = '1741783734';
        $sign = $this->providerSign($timestamp, '{"type":"DirectDeposit","msg":{"recordId":"1"}}');

        $this->assertFalse($this->createTestClass()->callVerifyWebhookSignature(
            '{"type":"DirectDeposit","msg":{"recordId":"2"}}',
            $timestamp,
            $sign
        ));
    }

    public function test_verify_webhook_signature_rejects_missing_headers(): void
    {
        $subject = $this->createTestClass();

        $this->assertFalse($subject->callVerifyWebhookSignature('{}', null, 'abc'));
        $this->assertFalse($subject->callVerifyWebhookSignature('{}', '1741783734', null));
        $this->assertFalse($subject->callVerifyWebhookSignature('{}', '1741783734', ''));
    }

    public function test_get_or_create_deposit_address_posts_reference_id_chain_and_notify_url(): void
    {
        Http::fake([
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['address' => 'TXYZabc', 'memo' => ''],
            ]),
        ]);

        $notifyUrl = 'https://example.invalid/external/api/deposit/notify/301';
        $data = $this->createTestClass()->callGetOrCreateDepositAddress('12-34', 'TRX', $notifyUrl);

        $this->assertSame('TXYZabc', $data['address']);
        Http::assertSent(function (Request $request) use ($notifyUrl): bool {
            return $request->url() === self::API_URL . '/getOrCreateAppDepositAddress'
                && $request->body() === '{"referenceId":"12-34","chain":"TRX","notifyUrl":"' . $notifyUrl . '"}'
                && $request->header('Sign')[0] === $this->providerSign($request->header('Timestamp')[0], $request->body());
        });
    }

    public function test_get_or_create_deposit_address_throws_when_data_missing(): void
    {
        Http::fake([
            self::API_URL . '/getOrCreateAppDepositAddress' => Http::response(['code' => 10000, 'msg' => 'success']),
        ]);

        $this->expectException(ApiException::class);

        $this->createTestClass()->callGetOrCreateDepositAddress('12-34', 'TRX', 'https://example.invalid/notify/301');
    }

    public function test_unbind_address_posts_chain_and_address(): void
    {
        Http::fake([
            self::API_URL . '/addressUnbinding' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => [
                    'unbound' => [['chain' => 'TRX', 'address' => 'TXYZabc']],
                    'unboundAt' => 1741783734,
                    'userID' => '',
                    'referenceID' => '12-34',
                ],
            ]),
        ]);

        $data = $this->createTestClass()->callUnbindAddress('TRX', 'TXYZabc');

        $this->assertSame('12-34', $data['referenceID']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/addressUnbinding'
            && $request->body() === '{"chain":"TRX","address":"TXYZabc"}');
    }

    public function test_list_deposit_records_posts_params_and_returns_data(): void
    {
        Http::fake([
            self::API_URL . '/getAppDepositRecordList' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['records' => [['recordId' => 'r1']], 'nextId' => ''],
            ]),
        ]);

        $data = $this->createTestClass()->callListDepositRecords([
            'chain' => 'TRX',
            'startAt' => 1741780000,
            'endAt' => 1741783600,
        ]);

        $this->assertSame('r1', $data['records'][0]['recordId']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/getAppDepositRecordList'
            && $request->body() === '{"chain":"TRX","startAt":1741780000,"endAt":1741783600}');
    }

    public function test_resend_webhooks_posts_params_and_returns_data(): void
    {
        Http::fake([
            self::API_URL . '/webhook/resend' => Http::response([
                'code' => 10000,
                'msg' => 'Success',
                'data' => ['resendCount' => 2],
            ]),
        ]);

        $data = $this->createTestClass()->callResendWebhooks([
            'recordIds' => ['r1', 'r2'],
            'startTimestamp' => 1741780000,
            'transactionType' => 'DirectDeposit',
        ]);

        $this->assertSame(2, $data['resendCount']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/webhook/resend'
            && $request->body() === '{"recordIds":["r1","r2"],"startTimestamp":1741780000,"transactionType":"DirectDeposit"}');
    }
}
