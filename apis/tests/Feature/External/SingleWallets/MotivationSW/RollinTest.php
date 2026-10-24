<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class RollinTest extends MotivationSWTestCase
{
    private string $roundId = 'round_rollin_001';

    private string $mtcode = 'mtcode_rollin_001';

    /** R-21：收到轉回（rollin）回調時，依局號找到轉出注單，以轉回金額減轉出額作為該局輸贏加入會員錢包，產生一筆轉回狀態的注單並建立總注單。 */
    #[Group('rules/vendor-motivationsw#R-21')]
    #[TestDox('rollin 後主錢包增加平台派彩額並產生 ROLLIN 注單')]
    public function test_rollin(): void
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
            'mtcode' => $this->mtcode . '_rollout',
            'status' => BetRecordStatusEnum::ROLLOUT,
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1),
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
        ]);

        $winVendorAmount = 400;
        $platformWinAmount = Common::numDiv($winVendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numAdd($walletBalance, $platformWinAmount);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/rollin", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 01:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'validbet' => $betVendorAmount,
                'bet' => $betVendorAmount,
                'win' => $winVendorAmount,
                'roomfee' => 0,
                'amount' => $winVendorAmount,
                'mtcode' => $this->mtcode,
                'createTime' => '2024-01-01 01:00:00',
                'rake' => 0,
                'gametype' => 'slot',
                'tableid' => 'table_001',
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::ROLLIN->value,
        ]);
    }

    /** R-29：魚機與街機的轉回回調不帶桌號（tableid 為空或沒送），仍照 R-21 結回錢包，不因缺桌號擋下。 */
    #[Group('rules/vendor-motivationsw#R-29')]
    #[TestDox('魚機 rollin 的 tableid 為 null 時照常結回：主錢包加上轉回額減轉出額')]
    public function test_fish_rollin_without_tableid(): void
    {
        // Arrange：照廠商實際送來的魚機轉回內容，amount = 轉出額 − bet + win；
        // 錢包餘額視為轉出扣款後的餘額，轉回時整個 amount 結回
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $rolloutVendorAmount = 1000;
        $platformRolloutAmount = Common::numDiv($rolloutVendorAmount, $this->vendorInfo->rate);

        MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode . '_rollout',
            'status' => BetRecordStatusEnum::ROLLOUT,
            'bet' => $platformRolloutAmount,
            'valid_bet' => $platformRolloutAmount,
            'win_lose' => Common::numMul($platformRolloutAmount, -1),
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
        ]);

        $betVendorAmount = 97;
        $winVendorAmount = 36;
        $rollinVendorAmount = $rolloutVendorAmount - $betVendorAmount + $winVendorAmount;
        $platformWinLose = Common::numDiv($rollinVendorAmount - $rolloutVendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numAdd($walletBalance, Common::numDiv($rollinVendorAmount, $this->vendorInfo->rate));

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/rollin", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 01:00:00',
                'gamehall' => 'cq9',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'validbet' => 0,
                'bet' => $betVendorAmount,
                'win' => $winVendorAmount,
                'roomfee' => 0,
                'amount' => $rollinVendorAmount,
                'mtcode' => $this->mtcode,
                'createTime' => '2024-01-01 01:00:00',
                'rake' => 0,
                'gametype' => 'fish',
                'tableid' => null,
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::ROLLIN->value,
            'win_lose' => $platformWinLose,
        ]);
    }

    /**
     * 廠商文件：老虎機／街機／魚機的轉回 validbet 固定送 0，有效投注要直接取 bet；牌桌／真人才用 validbet。
     */
    #[Group('rules/vendor-motivationsw#R-30')]
    #[TestDox('魚機 rollin 的有效投注取 bet，不採用廠商固定送 0 的 validbet；牌桌 rollin 仍採 validbet')]
    public function test_rollin_valid_bet_by_gametype(): void
    {
        // Arrange：兩局各自轉出，魚機與牌桌各結回一次
        $this->member->update(['balance' => 2000]);
        $rate = $this->vendorInfo->rate;

        $rounds = [
            'fish' => ['roundid' => 'round_fish_vb', 'mtcode' => 'mtcode_fish_vb', 'bet' => 97, 'validbet' => 0, 'expectedValidBet' => 97],
            'table' => ['roundid' => 'round_table_vb', 'mtcode' => 'mtcode_table_vb', 'bet' => 90, 'validbet' => 45, 'expectedValidBet' => 45],
        ];

        foreach ($rounds as $gametype => $round) {
            MotivationswBetRecord::create([
                'bet_id' => $round['roundid'],
                'username' => $this->username,
                'game_code' => $this->game->game_type,
                'mtcode' => $round['mtcode'] . '_rollout',
                'status' => BetRecordStatusEnum::ROLLOUT,
                'bet' => Common::numDiv(1000, $rate),
                'valid_bet' => Common::numDiv(1000, $rate),
                'win_lose' => Common::numMul(Common::numDiv(1000, $rate), -1),
                'rolling_chips' => 0,
                'bet_time' => '2024-01-01 00:00:00',
            ]);

            // Act
            $response = $this->withHeaders($this->wtokenHeader())
                ->postJson("{$this->baseurl}/transaction/game/rollin", [
                    'account' => $this->username,
                    'eventTime' => '2024-01-01 01:00:00',
                    'gamehall' => 'cq9',
                    'gamecode' => $this->game->game_type,
                    'roundid' => $round['roundid'],
                    'validbet' => $round['validbet'],
                    'bet' => $round['bet'],
                    'win' => 36,
                    'roomfee' => 0,
                    'amount' => 1000 - $round['bet'] + 36,
                    'mtcode' => $round['mtcode'],
                    'createTime' => '2024-01-01 01:00:00',
                    'rake' => 0,
                    'gametype' => $gametype,
                    'tableid' => $gametype === 'table' ? 'T1' : null,
                ]);

            // Assert
            $response->assertOk();
            $this->assertEquals('0', $response->json('status.code'), $gametype);
            $this->assertDatabaseHas('motivationsw_bet_records', [
                'mtcode' => $round['mtcode'],
                'status' => BetRecordStatusEnum::ROLLIN->value,
                'bet' => Common::numDiv($round['bet'], $rate),
                'valid_bet' => Common::numDiv($round['expectedValidBet'], $rate),
            ]);
        }
    }

    /** R-20：會員處於鎖分模式、或遊戲帳號與商戶已停用時，結算與轉回仍照常入帳；停用只擋下注，不擋結算。 */
    #[Group('rules/vendor-motivationsw#R-20')]
    #[TestDox('鎖分模式（event_mode>0）的會員 rollin 仍要結算入帳：結算不是下注，不受下注資格限制')]
    public function test_rollin_settles_for_member_in_event_mode(): void
    {
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance, 'event_mode' => 1]);

        $betVendorAmount = 200;
        $platformBetAmount = Common::numDiv($betVendorAmount, $this->vendorInfo->rate);

        MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode . '_rollout',
            'status' => BetRecordStatusEnum::ROLLOUT,
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1),
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
        ]);

        $winVendorAmount = 400;
        $expectedBalance = Common::numAdd($walletBalance, Common::numDiv($winVendorAmount, $this->vendorInfo->rate));

        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/rollin", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 01:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'validbet' => $betVendorAmount,
                'bet' => $betVendorAmount,
                'win' => $winVendorAmount,
                'roomfee' => 0,
                'amount' => $winVendorAmount,
                'mtcode' => $this->mtcode,
                'createTime' => '2024-01-01 01:00:00',
                'rake' => 0,
                'gametype' => 'slot',
                'tableid' => 'table_001',
            ]);

        $this->wallet->refresh();

        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));
    }

    /** R-22：收到轉回回調但該局找不到轉出注單時，回錯誤碼 1014。 */
    #[Group('rules/vendor-motivationsw#R-22')]
    #[TestDox('roundid 找不到 ROLLOUT 記錄時應回傳 status.code=1014')]
    public function test_rollin_without_rollout_record(): void
    {
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/rollin", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 01:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => 'non_existent_round',
                'validbet' => 100,
                'bet' => 100,
                'win' => 200,
                'roomfee' => 0,
                'amount' => 200,
                'mtcode' => $this->mtcode,
                'createTime' => '2024-01-01 01:00:00',
                'rake' => 0,
                'gametype' => 'slot',
                'tableid' => 'table_001',
            ]);

        $response->assertOk();
        $this->assertEquals('1014', $response->json('status.code'));
    }
}
