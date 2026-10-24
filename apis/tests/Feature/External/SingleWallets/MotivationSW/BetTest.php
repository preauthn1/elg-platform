<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class BetTest extends MotivationSWTestCase
{
    private string $roundId = 'round_bet_001';

    private string $mtcode = 'mtcode_bet_001';

    /** R-11：收到下注回調時，依匯率換算的投注額從會員錢包扣除（禮碼先扣、真金補足），產生一筆下注狀態的注單，並回扣款後的餘額。 */
    #[Group('rules/vendor-motivationsw#R-11')]
    #[TestDox('下注後主錢包扣除平台注額並產生 BET 狀態注單')]
    public function test_bet(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $vendorAmount = 200; // 遊戲商金額（平台金額 * rate）
        $platformAmount = Common::numDiv($vendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numSub($walletBalance, $platformAmount);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/bet", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 00:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'amount' => $vendorAmount,
                'mtcode' => $this->mtcode,
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::BET->value,
            'bet' => $platformAmount,
        ]);
    }

    /** R-12：下注時會員真金加禮碼不足以支付投注額，回錯誤碼 1005，不建注單、不扣款。 */
    #[Group('rules/vendor-motivationsw#R-12')]
    #[TestDox('餘額不足時應回傳 status.code=1005 且不建立注單')]
    public function test_bet_insufficient_balance(): void
    {
        $this->member->update(['balance' => 10]);

        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/bet", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 00:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'amount' => 10000,
                'mtcode' => $this->mtcode,
            ]);

        $response->assertOk();
        $this->assertEquals('1005', $response->json('status.code'));
        $this->assertDatabaseMissing('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'bet' => Common::numDiv(10000, $this->vendorInfo->rate),
        ]);
    }

    /** R-14：轉出（rollout）與下注的處理相同，差別只在注單狀態記為轉出；轉出的額度之後由轉回結回。 */
    #[Group('rules/vendor-motivationsw#R-14')]
    #[TestDox('/rollout 與 /bet 邏輯相同但注單狀態應為 ROLLOUT')]
    public function test_rollout(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $vendorAmount = 100;
        $platformAmount = Common::numDiv($vendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numSub($walletBalance, $platformAmount);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/rollout", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 00:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'amount' => $vendorAmount,
                'mtcode' => $this->mtcode,
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'status' => BetRecordStatusEnum::ROLLOUT->value,
        ]);
    }
}
