<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class RefundTest extends MotivationSWTestCase
{
    private string $roundId = 'round_refund_001';

    private string $mtcode = 'mtcode_refund_001';

    /** R-15：收到退款回調時，依交易代碼（mtcode）找到原下注注單，把投注額退回會員錢包（原本扣的禮碼退回禮碼錢包），注單狀態改為已退款，並回退款後的餘額。 */
    #[Group('rules/vendor-motivationsw#R-15')]
    #[TestDox('退款後主錢包回復原注額，注單狀態更新為 REFUND')]
    public function test_refund(): void
    {
        // Arrange
        $walletBalance = 1500;
        $this->member->update(['balance' => $walletBalance]);

        $vendorAmount = 200;
        $platformBetAmount = Common::numDiv($vendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numAdd($walletBalance, $platformBetAmount);

        MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::BET,
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1),
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
        ]);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/refund", [
                'account' => $this->username,
                'mtcode' => $this->mtcode,
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::REFUND->value,
            'valid_bet' => 0,
        ]);
    }

    /** R-27：同一筆交易代碼已經退過款，再收到退款回調時回錯誤碼 1100，不再退款。 */
    #[Group('rules/vendor-motivationsw#R-27')]
    #[TestDox('重複退款應回傳 status.code=1100')]
    public function test_refund_duplicate(): void
    {
        $platformBetAmount = Common::numDiv(100, $this->vendorInfo->rate);

        MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::REFUND,
            'bet' => $platformBetAmount,
            'valid_bet' => 0,
            'win_lose' => 0,
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
        ]);

        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/refund", [
                'account' => $this->username,
                'mtcode' => $this->mtcode,
            ]);

        $response->assertOk();
        $this->assertEquals('1100', $response->json('status.code'));
    }

    /** R-28：收到退款回調但交易代碼找不到原下注注單時，回錯誤碼 1100，不動錢包。 */
    #[Group('rules/vendor-motivationsw#R-28')]
    #[TestDox('mtcode 不存在時應回傳 status.code=1100')]
    public function test_refund_not_found(): void
    {
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/refund", [
                'account' => $this->username,
                'mtcode' => 'non_existent_mtcode',
            ]);

        $response->assertOk();
        $this->assertEquals('1100', $response->json('status.code'));
    }
}
