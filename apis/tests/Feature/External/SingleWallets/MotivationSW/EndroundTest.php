<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EndroundTest extends MotivationSWTestCase
{
    private string $roundId = 'round_endround_001';

    private string $mtcode = 'mtcode_endround_001';

    /** R-16：收到結算（endround）回調時，該局每一筆派彩依匯率換算後加入會員錢包，每筆派彩各產生一筆結算狀態的注單，並建立或更新該局的總注單，總注單輸贏等於派彩合計減投注合計。 */
    #[Group('rules/vendor-motivationsw#R-16')]
    #[TestDox('結算後主錢包增加平台派彩額並產生 ENDROUND 注單')]
    public function test_endround_win(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $betVendorAmount = 200;
        $platformBetAmount = Common::numDiv($betVendorAmount, $this->vendorInfo->rate);

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

        $winVendorAmount = 400;
        $platformWinAmount = Common::numDiv($winVendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numAdd($walletBalance, $platformWinAmount);

        $endroundData = json_encode([
            [
                'mtcode' => $this->mtcode . '_end',
                'amount' => $winVendorAmount,
                'eventtime' => '2024-01-01 01:00:00',
                'validbet' => $betVendorAmount,
            ],
        ]);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/endround", [
                'account' => $this->username,
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'data' => $endroundData,
                'createTime' => '2024-01-01 01:00:00',
                'eventTime' => '2024-01-01 01:00:00',
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'status' => BetRecordStatusEnum::ENDROUND->value,
            'win_lose' => $platformWinAmount,
        ]);

        // 總注單輸贏 = 派彩 - 下注，不是只有 -下注
        $betRecordId = MotivationswBetRecord::where('bet_id', $this->roundId)->orderBy('id')->value('id');
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendorInfo->vendor_id,
            'bet_record_id' => $betRecordId,
            'bet' => $platformBetAmount,
            'win_lose' => Common::numSub($platformWinAmount, $platformBetAmount),
        ]);
    }

    /** R-17：同一局有多筆派彩時，總注單的輸贏累加每一筆派彩，不會只算到第一筆。 */
    #[Group('rules/vendor-motivationsw#R-17')]
    #[TestDox('同一局多筆派彩時，總注單輸贏累加每一筆派彩')]
    public function test_endround_with_multiple_payout_rows_accumulates_total_win_lose(): void
    {
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $platformBetAmount = Common::numDiv(200, $this->vendorInfo->rate);
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

        $payouts = [300, 100];
        $platformPayoutTotal = Common::numDiv(array_sum($payouts), $this->vendorInfo->rate);
        $endroundData = json_encode(array_map(fn (int $amount, int $i) => [
            'mtcode' => "{$this->mtcode}_end_{$i}",
            'amount' => $amount,
            'eventtime' => '2024-01-01 01:00:00',
            'validbet' => 200,
        ], $payouts, array_keys($payouts)));

        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/endround", [
                'account' => $this->username,
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'data' => $endroundData,
                'createTime' => '2024-01-01 01:00:00',
                'eventTime' => '2024-01-01 01:00:00',
            ]);

        $this->wallet->refresh();

        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare(Common::numAdd($walletBalance, $platformPayoutTotal), $this->wallet->balance));

        $betRecordId = MotivationswBetRecord::where('bet_id', $this->roundId)->orderBy('id')->value('id');
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendorInfo->vendor_id,
            'bet_record_id' => $betRecordId,
            'bet' => $platformBetAmount,
            'win_lose' => Common::numSub($platformPayoutTotal, $platformBetAmount),
        ]);
    }

    /** R-20：會員處於鎖分模式、或遊戲帳號與商戶已停用時，結算與轉回仍照常入帳；停用只擋下注，不擋結算。 */
    #[Group('rules/vendor-motivationsw#R-20')]
    #[TestDox('鎖分模式（event_mode>0）的會員 endround 仍要結算入帳：結算不是下注，不受下注資格限制')]
    public function test_endround_settles_for_member_in_event_mode(): void
    {
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance, 'event_mode' => 1]);

        $betVendorAmount = 200;
        $platformBetAmount = Common::numDiv($betVendorAmount, $this->vendorInfo->rate);

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

        $winVendorAmount = 400;
        $expectedBalance = Common::numAdd($walletBalance, Common::numDiv($winVendorAmount, $this->vendorInfo->rate));

        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/endround", [
                'account' => $this->username,
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'data' => json_encode([[
                    'mtcode' => $this->mtcode . '_end',
                    'amount' => $winVendorAmount,
                    'eventtime' => '2024-01-01 01:00:00',
                    'validbet' => $betVendorAmount,
                ]]),
                'createTime' => '2024-01-01 01:00:00',
                'eventTime' => '2024-01-01 01:00:00',
            ]);

        $this->wallet->refresh();

        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));
    }

    /** R-18：同一局已經結算過，再收到結算回調時直接回當前餘額，不重複入帳、不重複建注單。 */
    #[Group('rules/vendor-motivationsw#R-18')]
    #[TestDox('roundid 已有 ENDROUND 記錄時重複呼叫應直接回傳餘額而不重算')]
    public function test_endround_idempotency(): void
    {
        // Arrange
        $walletBalance = 3000;
        $this->member->update(['balance' => $walletBalance]);

        MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::ENDROUND,
            'bet' => 100,
            'valid_bet' => 100,
            'win_lose' => 200,
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
        ]);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/endround", [
                'account' => $this->username,
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'data' => json_encode([
                    [
                        'mtcode' => $this->mtcode . '_dup',
                        'amount' => 800,
                        'eventtime' => '2024-01-01 02:00:00',
                        'validbet' => 200,
                    ],
                ]),
                'createTime' => '2024-01-01 02:00:00',
                'eventTime' => '2024-01-01 02:00:00',
            ]);

        $this->wallet->refresh();

        // Assert：餘額不應改變
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($walletBalance, $this->wallet->balance));
    }
}
