<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets;

use App\Http\Controllers\External\SingleWallets\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 單一錢包回調共用基底的憑證遮罩界線（target/op_tickets#3076）。
 *
 * logRequest() 原本把整包請求參數原文寫進 log，三方靜態憑證（Ace333SW 的 password、
 * Rcb988／Imperium／AFBSW 的 key、WE 的 appSecret）因此明文落地。
 *
 * 遮罩只施加於 log，**注單 info 刻意保留原文**（RB 2026-09-07 裁決）——它是三方對帳與
 * 爭議時的原始證據。本測試同時守住這兩側：log 要遮、info 不要遮。
 *
 * php artisan test tests/Feature/External/SingleWallets/CallbackPayloadMaskTest.php
 */
class CallbackPayloadMaskTest extends TestCase
{
    /**
     * 取得可從測試呼叫 protected 方法的基底 controller。
     */
    private function controller(): BaseController
    {
        return new class extends BaseController
        {
            public function exposeLogRequest(Request $request, string $action): void
            {
                $this->logRequest($request, $action);
            }

            public function exposeAppendParamsToInfo(string $info, array $params): string
            {
                return $this->appendParamsToInfo($info, $params);
            }
        };
    }

    /**
     * 取出 logRequest 寫進 log 的 context，供各測試斷言。
     */
    private function captureLogContext(array $params): array
    {
        $captured = [];

        Log::shouldReceive('info')
            ->once()
            ->andReturnUsing(function (string $action, array $context) use (&$captured): void {
                $captured = $context;
            });

        $request = Request::create('http://localhost/external/sw/afbsw', 'POST', $params);
        $this->controller()->exposeLogRequest($request, 'test_action');

        return $captured;
    }

    /**
     * 靜態憑證（拿到即可用、不會過期）必須遮罩：password 已在 MaskTrait 的秘密類，
     * key 與 appSecret 是本次補上的——三家遊戲商的回調都用這兩個鍵送憑證。
     */
    public function test_log_request_masks_static_credentials(): void
    {
        $context = $this->captureLogContext([
            'key' => 'abcdef1234567890',
            'password' => 'p@ssw0rd12345678',
            'appSecret' => 'secret9876543210',
            'account' => 'player001',
        ]);

        $this->assertSame('************7890', $context['params']['key']);
        $this->assertSame('************5678', $context['params']['password']);
        $this->assertSame('************3210', $context['params']['appSecret']);
        $this->assertSame('player001', $context['params']['account'], '非憑證欄位不應被遮罩');
    }

    /**
     * 簽名／雜湊欄位刻意保留明文——遮掉會讓「從 log 重算簽名」的排查能力失效
     * （CLAUDE.md 金流／安全紅線與 MaskTrait 的既定政策）。
     */
    public function test_log_request_preserves_signature_fields(): void
    {
        $context = $this->captureLogContext([
            'sign' => 'c4ca4238a0b923820dcc509a6f75849b',
            'Signature' => 'eccbc87e4b5ce2fe28308fd9f2a7baf3',
            'hash' => 'a87ff679a2f3e71d9181a67b7542122c',
            'hashkey' => 'e4da3b7fbbce2345d7772b0674a318d5',
        ]);

        $this->assertSame('c4ca4238a0b923820dcc509a6f75849b', $context['params']['sign']);
        $this->assertSame('eccbc87e4b5ce2fe28308fd9f2a7baf3', $context['params']['Signature']);
        $this->assertSame('a87ff679a2f3e71d9181a67b7542122c', $context['params']['hash']);
        $this->assertSame('e4da3b7fbbce2345d7772b0674a318d5', $context['params']['hashkey']);
    }

    /**
     * 原始 body 字串（getContent()）不再落 log：它與 params 是同一份內容，
     * 但無法套用陣列遮罩，留著等於讓憑證從第二個欄位漏出去。
     */
    public function test_log_request_no_longer_logs_raw_content(): void
    {
        $context = $this->captureLogContext(['key' => 'abcdef1234567890']);

        $this->assertArrayNotHasKey('Content', $context);
    }

    /**
     * 注單 info 保留原文，**不套遮罩**（RB 2026-09-07 裁決）。
     *
     * 這條是刻意的反向守衛：info 是三方對帳與爭議時的原始證據，金額或狀態對不上時要靠它
     * 還原當時收到什麼。若日後有人「順手」把 logRequest 的遮罩也套到這裡，本測試會紅。
     */
    public function test_append_params_to_info_keeps_raw_params(): void
    {
        $info = $this->controller()->exposeAppendParamsToInfo('[]', [
            'key' => 'abcdef1234567890',
            'password' => 'p@ssw0rd12345678',
            'betAmount' => '10.00',
        ]);

        $decoded = json_decode($info, true);

        $this->assertSame('abcdef1234567890', $decoded[0]['key']);
        $this->assertSame('p@ssw0rd12345678', $decoded[0]['password']);
        $this->assertSame('10.00', $decoded[0]['betAmount']);
    }

    /**
     * 既有行為不能壞：info 原有內容要保留，新參數追加在後。
     */
    public function test_append_params_to_info_keeps_existing_entries(): void
    {
        $info = $this->controller()->exposeAppendParamsToInfo(
            json_encode([['stage' => 'bet']]),
            ['stage' => 'settle', 'amount' => '20.00'],
        );

        $decoded = json_decode($info, true);

        $this->assertCount(2, $decoded);
        $this->assertSame('bet', $decoded[0]['stage']);
        $this->assertSame('settle', $decoded[1]['stage']);
        $this->assertSame('20.00', $decoded[1]['amount']);
    }
}
