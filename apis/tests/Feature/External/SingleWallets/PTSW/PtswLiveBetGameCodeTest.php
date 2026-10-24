<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Helpers\Common;
use App\Models\Game;
use App\Models\VendorInfoGame;
use App\Vendors\Models\PtswBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * PTSW live 桌下注 gameCodeName 映射回歸測試。
 *
 * Bug：bet() 對 live 桌（gameRoundCode 以 live_ 開頭）已將遊戲映射成統一的 ubal，
 * getGameByCode 與 BetRecord 都用映射後的 ubal，但 isMemberAbleToBet 卻傳「原始 gameCodeName」
 * （個別 live 桌，如 3brgl）。個別 live 桌在 games 表 status=0 且無 vendor_info_games 設定，
 * 導致 assertRegularGameAvailability 撈不到 vendor_info_game → BetException →
 * 誤回 ERR_TRANSACTION_DECLINED / Account could not bet yet。
 *
 * 修正：isMemberAbleToBet 改傳已解析好的 $game（ubal）物件。
 *
 * 此檢查僅「非 event_v2 會員」會觸發（event_v2 會跳過 vendor_info_games 檢查），
 * 故 member 必須 is_event_v2 = false（factory member 無 eventV2Joins，預設即為 false）才能鎖住回歸。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswLiveBetGameCodeTest.php --compact
 */
class PtswLiveBetGameCodeTest extends PtswTestCase
{
    /**
     * 個別 live 桌（games status=0、無 vendor_info_games）下注：
     *   - gameRoundCode 以 live_ 開頭
     *   - gameCodeName 為個別 live 桌 code（非 ubal）
     * 預期：
     *   - 修正後不再回 ERR_TRANSACTION_DECLINED / Account could not bet yet
     *   - 進入正常下注流程（成功扣款、產生 BetRecord，且記在映射後的 ubal 遊戲上）
     */
    #[Group('rules/vendor-ptsw#R-10')]
    public function test_live_table_bet_with_individual_game_code_is_not_declined(): void
    {
        // 確認此 member 非 event_v2，才會真正走到 vendor_info_games 檢查（鎖住回歸）
        $this->assertFalse($this->member->is_event_v2, 'member 必須非 event_v2 才能鎖住此回歸');

        // 建立個別 live 桌：在 games 表 status=0，且不建立任何 vendor_info_games 設定。
        // 平台設計上 PT live 一律走 ubal，故個別桌不會被配置。
        $individualLiveGame = $this->vendor->games()->save(new Game([
            'type_id' => 0,
            'name' => 'live three card brag',
            'game_type' => '3brgl',
            'status' => 0,
            'open_window' => 0,
            'image' => 'https://example.invalid',
            'admin' => 0,
        ]));

        // 防禦性確認：此個別桌確實沒有 status=1 的 vendor_info_games（舊碼會卡在這裡）
        $this->assertFalse(
            VendorInfoGame::query()
                ->where('vendor_info_id', $this->vendorInfo->id)
                ->where('game_id', $individualLiveGame->id)
                ->where('status', 1)
                ->exists(),
            '個別 live 桌不應有 status=1 的 vendor_info_games（重現 bug 前提）',
        );

        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $betAmount = '30.0';

        $response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-live-bet-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'live_15005005080000',
            'gameCodeName' => '3brgl',
            'transactionCode' => 'tc-live-bet-1',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-20T10:00:00Z',
        ]);

        $response->assertStatus(200);
        // 核心斷言：不得因 gameCodeName 映射不一致而被拒絕
        $response->assertJsonMissing(['error' => 'ERR_TRANSACTION_DECLINED']);
        $response->assertJsonMissing(['description' => 'Account could not bet yet']);
        $response->assertJsonMissing(['description' => 'The gameCodeName is not supported']);

        // 進入正常下注流程：產生 1 筆 BetRecord，且 game_code 記為映射後的 ubal（非個別 live 桌 code）
        $this->assertEquals(1, PtswBetRecord::count(), '正常下注流程應產生 1 筆 PtswBetRecord');
        $betRecord = PtswBetRecord::first();
        $this->assertEquals('ubal', $betRecord->game_code, 'BetRecord 的 game_code 應為映射後的 ubal');
        $this->assertNotEquals('3brgl', $betRecord->game_code, 'BetRecord 不應記為個別 live 桌 code');

        // 餘額正常扣款：1000 - 30 = 970
        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $betAmount, 4),
            $this->member->balance,
            '下注後應正常扣款',
        );
    }

    /**
     * 回歸保護：slot 正常下注（gameRoundCode 不以 live_ 開頭，gameCodeName 即為遊戲 code）仍可通過。
     */
    #[Group('rules/vendor-ptsw#R-10')]
    public function test_slot_bet_still_passes(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $betAmount = '30.0';

        $response = $this->postJson("{$this->baseurl}/bet", [
            'requestId' => 'req-slot-bet-1',
            'username' => $this->username,
            'externalToken' => $this->token,
            'gameRoundCode' => 'slot_15005005080001',
            'gameCodeName' => 'ubal',
            'transactionCode' => 'tc-slot-bet-1',
            'amount' => $betAmount,
            'transactionDate' => '2026-06-20T10:00:00Z',
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error' => 'ERR_TRANSACTION_DECLINED']);

        $this->assertEquals(1, PtswBetRecord::count());
        $betRecord = PtswBetRecord::first();
        $this->assertEquals('ubal', $betRecord->game_code);

        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $betAmount, 4),
            $this->member->balance,
        );
    }
}
