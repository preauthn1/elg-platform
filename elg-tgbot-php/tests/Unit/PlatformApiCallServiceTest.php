<?php

namespace Tests\Unit;

use App\DTOs\DepositInfoDTO;
use App\DTOs\HomepageInfoDTO;
use App\DTOs\PlatformWalletBalanceDTO;
use App\Enums\ErrorCodeEnum;
use App\Enums\PlatformApiCodeEnum;
use App\Helpers\AESHelper;
use App\Singletons\PlatformApiCallService;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class PlatformApiCallServiceTest extends TestCase
{
    private $clientMock;

    private $aesHelperMock;

    private $platformApiCallService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientMock = Mockery::mock(Client::class);
        $this->aesHelperMock = Mockery::mock(AESHelper::class);
        $this->app->instance(Client::class, $this->clientMock);
        $this->app->instance(AESHelper::class, $this->aesHelperMock);

        $this->platformApiCallService = new PlatformApiCallService($this->botId, 'testChatId');
    }

    public function test_is_account_exists()
    {
        $response = new Response(200, [], json_encode(['code' => PlatformApiCodeEnum::SUCCESS->value]));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->isAccountExists();

        $this->assertTrue($result);
    }

    public function test_account_not_exists()
    {
        $response = new Response(200, [], json_encode(['code' => 9999]));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->isAccountExists();

        $this->assertFalse($result);
    }

    public function test_get_homepage_info()
    {
        $responseData = [
            'code' => 1000,
            'msg' => 'success',
            'data' => [
                'event_link' => 'https://example.invalid/event',
                'withdrawal_link' => 'https://example.invalid/withdrawal',
                'cs_link' => 'https://example.invalid/cs',
                'wallet_link' => 'https://example.invalid/wallet',
                'launch_link' => 'https://example.invalid/launch',
            ],
        ];
        $response = new Response(200, [], json_encode($responseData));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->getHomepageInfo();

        $this->assertInstanceOf(HomepageInfoDTO::class, $result);
        $this->assertEquals($responseData['data']['event_link'], $result->eventLink);
        $this->assertEquals($responseData['data']['withdrawal_link'], $result->withdrawalLink);
        $this->assertEquals($responseData['data']['cs_link'], $result->customerServiceLink);
        $this->assertEquals($responseData['data']['wallet_link'], $result->walletLink);
        $this->assertEquals($responseData['data']['launch_link'], $result->siteLaunchLink);
    }

    public function test_get_homepage_info_failed()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionCode(ErrorCodeEnum::PLATFORM_API_CALL_NOT_SUCCESS->value);

        $responseData = [
            'code' => 9999,
            'msg' => 'error',
            'data' => null,
        ];
        $response = new Response(200, [], json_encode($responseData));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->getHomepageInfo();
    }

    public function test_get_wallet_balance()
    {
        $responseData = [
            'code' => 1000,
            'msg' => 'success',
            'data' => [
                'member_id' => 48722,
                'wallet' => [
                    [
                        'id' => 1,
                        'name' => 'Cash',
                        'balance' => '123.45',
                    ],
                    [
                        'id' => 2,
                        'name' => 'Game',
                        'balance' => '234.56',
                    ],
                    [
                        'id' => 1002,
                        'name' => 'Rolling Chip',
                        'balance' => '111.23',
                    ],
                ],
                'is_multiple_wallet' => true,
            ],
        ];

        $response = new Response(200, [], json_encode($responseData));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->getWalletBalance();

        $this->assertInstanceOf(PlatformWalletBalanceDTO::class, $result);
        $this->assertEquals($responseData['data']['member_id'], $result->platformMemberId);
        $this->assertEquals($responseData['data']['wallet'][0]['balance'], $result->mainWallet);
        $this->assertEquals($responseData['data']['wallet'][1]['balance'], $result->gameWallet);
        $this->assertEquals($responseData['data']['wallet'][2]['balance'], $result->rollingChipWallet);
        $this->assertEquals($responseData['data']['is_multiple_wallet'], $result->isMultipleWallet);
    }

    public function test_get_deposit_infos()
    {
        $responseData = [
            'code' => 1000,
            'message' => 'success',
            'data' => [
                [
                    'order_type_id' => 0,
                    'name' => '自助上分',
                    'cf_id' => 5,
                    'cf_name' => 'AutoDeposit',
                    'image_path' => 'https://example.invalid/image1',
                    'popup_text' => '',
                    'url' => 'https://example.invalid/deposit',
                ],
                [
                    'order_type_id' => 150,
                    'name' => 'TRUEMONEY',
                    'cf_id' => 3,
                    'cf_name' => 'AllPay',
                    'popup_text' => '',
                    'image_path' => 'https://example.invalid/image2',
                    'url' => 'https://example.invalid/deposit',
                ],
            ],
        ];
        $response = new Response(200, [], json_encode($responseData));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->getDepositInfos();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        foreach ($result as $i => $depositInfo) {
            $this->assertInstanceOf(DepositInfoDTO::class, $depositInfo);
            $this->assertEquals($responseData['data'][$i]['order_type_id'], $depositInfo->orderTypeId);
            $this->assertEquals($responseData['data'][$i]['name'], $depositInfo->name);
            $this->assertEquals($responseData['data'][$i]['cf_id'], $depositInfo->cfId);
            $this->assertEquals($responseData['data'][$i]['cf_name'], $depositInfo->cfName);
            $this->assertEquals($responseData['data'][$i]['image_path'], $depositInfo->imagePath);
            $this->assertEquals($responseData['data'][$i]['popup_text'] ?? '', $depositInfo->popupText);
        }
    }

    public function test_is_payment_password_set()
    {
        $response = new Response(200, [], json_encode(['code' => PlatformApiCodeEnum::SUCCESS->value]));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->isPaymentPasswordSet();

        $this->assertTrue($result);
    }

    public function test_payment_password_not_set()
    {
        $response = new Response(200, [], json_encode(['code' => 9999]));
        $this->clientMock->shouldReceive('post')->andReturn($response);
        $this->platformApiCallService->setClient($this->clientMock);

        $result = $this->platformApiCallService->isPaymentPasswordSet();

        $this->assertFalse($result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
