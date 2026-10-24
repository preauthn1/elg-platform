<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Facades\BotNotify;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW keepAlive（保持玩家登錄會話活動）端點基本行為測試。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswKeepAliveTest.php --compact
 */
class PtswKeepAliveTest extends PtswTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 見 PtswSettlementTest::setUp() 註解。
        BotNotify::shouldReceive('send')->zeroOrMoreTimes();
    }

    #[TestDox('keepAlive：token 正確時原樣回傳 requestId')]
    #[Group('rules/vendor-ptsw#R-8')]
    public function test_keep_alive_success_returns_request_id(): void
    {
        $response = $this->postJson("{$this->baseurl}/keepAlive", [
            'requestId' => 'req-keep-alive-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'timeFromLastAction' => 1500,
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);
        $response->assertExactJson([
            'requestId' => 'req-keep-alive-1',
        ]);
    }
}
