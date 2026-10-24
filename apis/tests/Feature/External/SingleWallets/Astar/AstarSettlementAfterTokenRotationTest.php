<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use App\Enums\Vendor\VendorIdEnum;
use App\Vendors\Astar\Enums\BetRecordStatusEnum;
use App\Vendors\Astar\Enums\ErrorCodeEnum;
use App\Vendors\Models\AstarBetRecord;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;

/**
 * php artisan test tests/Feature/External/SingleWallets/Astar/AstarSettlementAfterTokenRotationTest.php --compact
 *
 * 背景（op_tickets#3068，2026-09-05 TPower 事故）：
 * Astar 的 rollin / refund 重送帶的是「下注當時」的 gametoken，玩家重登後 astar_accounts.session_token 已換新，
 * 舊實作以「帳號現行 session_token == 請求 gametoken」找帳號，重送永遠回 Player not found or invalid，
 * 已扣款的 rollout 永遠卡在 ROLLOUT。refund 依 Astar 規格只帶 mtcode（= round 字串），連 gametoken 都沒有。
 *
 * 本測試鎖定：結算與退款必須以「round → 注單 → 帳號」定位，gametoken 只需符合注單當時的 token 或帳號現行 token。
 */
class AstarSettlementAfterTokenRotationTest extends AstarTestCase
{
    /** 玩家重登後帳號現行 token（與下注當時的 $this->gametoken 不同） */
    private string $rotatedToken = [REDACTED]';

    /** 與注單、帳號都對不上的 token */
    private string $strangerToken = [REDACTED]';

    private function seedRollout(string $round, string $bet = '40.00'): AstarBetRecord
    {
        return AstarBetRecord::forceCreate([
            'bet_id' => $round,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => '-' . $bet,
            'status' => BetRecordStatusEnum::ROLLOUT,
            'vendor_username' => $this->username,
            'bet_time' => now()->toDateTimeString(),
            'game_code' => $this->game->game_type,
            'session_token' => $this->gametoken,
            'info' => json_encode([]),
        ]);
    }

    private function rotateAccountToken(): void
    {
        $this->account->update(['session_token' => $this->rotatedToken]);
    }

    private function postRollin(string $round, string $gametoken, string $id = '', ?string $csToken = [REDACTED] TestResponse
    {
        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/table/rollin";

        return $this->withHeaders(['Authorization' => $csToken ?? $this->csToken])->postJson($url, [
            'gametoken' => $gametoken,
            'amount' => '80.00',
            'bet' => '40.00',
            'betpoint' => 'BANKER:40.00@40.00@40.00#',
            'datetime' => now()->tz('-04:00')->toRfc3339String(),
            'gamecode' => $this->game->game_type,
            'id' => $id ?: $this->username,
            'mtcode' => 'rel-rollin-' . $round,
            'rake' => '0.00',
            'result' => 'BANKER',
            'roomfee' => '0.00',
            'round' => $round,
            'roundnumber' => '246362074',
            'tableid' => '3006',
            'tablename' => 'BACCARAT#LV07',
            'tabletype' => '1',
            'validbet' => '40.00',
            'win' => '40.00',
        ]);
    }

    private function postRefund(array $payload, ?string $csToken = [REDACTED], ?string $gamehall = null): TestResponse
    {
        $hall = $gamehall ?? $this->gamehall;
        $url = "{$this->baseurl}/gamepool/{$hall}/game/bet/refund";

        return $this->withHeaders(['Authorization' => $csToken ?? $this->csToken])->postJson($url, $payload);
    }

    #[Group('rules/vendor-astar#R-14')]
    #[Group('rules/vendor-astar#R-16')]
    public function test_rollin_retry_with_original_gametoken_succeeds_after_account_token_rotated(): void
    {
        $round = 'VW1194683701';
        $this->seedRollout($round);
        $this->wallet->update(['balance' => '960.0000']);
        $this->rotateAccountToken();

        $response = $this->postRollin($round, $this->gametoken);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1040.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'win_lose' => '40.00',
            'status' => BetRecordStatusEnum::ROLLIN->value,
        ]);
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => VendorIdEnum::ASTAR->value,
            'member_id' => $this->member->id,
            'bet_record_id' => AstarBetRecord::where('bet_id', $round)->value('id'),
        ]);
    }

    #[Group('rules/vendor-astar#R-14')]
    public function test_rollin_with_account_current_token_still_succeeds_after_rotation(): void
    {
        $round = 'VW1194683702';
        $this->seedRollout($round);
        $this->wallet->update(['balance' => '960.0000']);
        $this->rotateAccountToken();

        $response = $this->postRollin($round, $this->rotatedToken);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1040.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::ROLLIN->value,
        ]);
    }

    #[Group('rules/vendor-astar#R-15')]
    public function test_rollin_rejects_gametoken_matching_neither_bet_record_nor_account(): void
    {
        $round = 'VW1194683703';
        $this->seedRollout($round);
        $this->wallet->update(['balance' => '960.0000']);
        $this->rotateAccountToken();

        $response = $this->postRollin($round, $this->strangerToken);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::INVALID_GAMETOKEN->value);

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::ROLLOUT->value,
        ]);
        $this->assertSame('960.0000', (string) $this->wallet->fresh()->balance);
    }

    #[Group('rules/vendor-astar#R-15')]
    public function test_rollin_rejects_player_id_not_matching_bet_record(): void
    {
        $round = 'VW1194683704';
        $this->seedRollout($round);
        $this->wallet->update(['balance' => '960.0000']);

        $response = $this->postRollin($round, $this->gametoken, 'v101i2a00000001');

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::PLAYER_NOT_FOUND->value);

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::ROLLOUT->value,
        ]);
        $this->assertSame('960.0000', (string) $this->wallet->fresh()->balance);
    }

    #[Group('rules/vendor-astar#R-13')]
    public function test_rollin_unknown_round_returns_order_not_found_even_when_token_rotated(): void
    {
        $this->wallet->update(['balance' => '960.0000']);
        $this->rotateAccountToken();

        $response = $this->postRollin('VW1194683799', $this->gametoken);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::ORDER_NOT_FOUND->value);
        $this->assertSame('960.0000', (string) $this->wallet->fresh()->balance);
    }

    #[Group('rules/vendor-astar#R-19')]
    #[Group('rules/vendor-astar#R-20')]
    #[Group('rules/vendor-astar#R-21')]
    public function test_refund_with_only_mtcode_as_round_string_succeeds_after_account_token_rotated(): void
    {
        $round = 'VW1194217901';
        $this->seedRollout($round, '50.00');
        $this->wallet->update(['balance' => '950.0000']);
        $this->rotateAccountToken();

        $response = $this->postRefund(['mtcode' => $round]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1000.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::REFUND->value,
            'valid_bet' => 0,
            'win_lose' => 0,
        ]);
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => VendorIdEnum::ASTAR->value,
            'member_id' => $this->member->id,
            'bet_record_id' => AstarBetRecord::where('bet_id', $round)->value('id'),
        ]);
    }

    #[Group('rules/vendor-astar#R-19')]
    public function test_refund_with_rollout_style_mtcode_succeeds(): void
    {
        $round = 'VW1194217902';
        $this->seedRollout($round, '50.00');
        $this->wallet->update(['balance' => '950.0000']);

        $response = $this->postRefund(['mtcode' => 'rel-rollout-' . $round]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1000.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::REFUND->value,
        ]);
    }

    #[Group('rules/vendor-astar#R-22')]
    public function test_refund_unknown_round_returns_order_not_found(): void
    {
        $this->wallet->update(['balance' => '950.0000']);

        $response = $this->postRefund(['mtcode' => 'VW1194217999']);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::ORDER_NOT_FOUND->value);
        $this->assertSame('950.0000', (string) $this->wallet->fresh()->balance);
    }

    #[Group('rules/vendor-astar#R-22')]
    public function test_refund_is_idempotent_for_already_refunded_round(): void
    {
        $round = 'VW1194217903';
        $this->seedRollout($round, '50.00');
        $this->wallet->update(['balance' => '950.0000']);

        $this->postRefund(['mtcode' => $round])->assertJsonPath('status.code', '0');
        $second = $this->postRefund(['mtcode' => $round]);

        $second->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::ORDER_NOT_FOUND->value);
        $this->assertSame('1000.0000', (string) $this->wallet->fresh()->balance);
    }

    /**
     * 商戶授權（GAMEHALL + CS_TOKEN）必須先於注單查詢：
     * 否則沒有 CS_TOKEN 的請求也能靠 34／4 的差異探知某個 round 是否還在 ROLLOUT。
     */
    #[Group('rules/vendor-astar#R-13')]
    #[Group('rules/vendor-astar#R-3')]
    public function test_rollin_with_wrong_cs_token_returns_token_invalid_before_round_lookup(): void
    {
        $this->wallet->update(['balance' => '960.0000']);

        $response = $this->postRollin('VW1194683798', $this->gametoken, '', 'WrongCsToken');

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::TOKEN_INVALID->value);
    }

    #[Group('rules/vendor-astar#R-20')]
    public function test_refund_from_other_gamehall_with_its_own_cs_token_cannot_touch_this_round(): void
    {
        $round = 'VW1194217904';
        $this->seedRollout($round, '50.00');
        $this->wallet->update(['balance' => '950.0000']);

        $response = $this->postRefund(['mtcode' => $round], 'OtherHallToken', 'VW2');

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::TOKEN_INVALID->value);

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::ROLLOUT->value,
        ]);
        $this->assertSame('950.0000', (string) $this->wallet->fresh()->balance);
    }

    #[Group('rules/vendor-astar#R-20')]
    public function test_refund_when_vendor_info_disabled_returns_token_invalid(): void
    {
        $round = 'VW1194217905';
        $this->seedRollout($round, '50.00');
        $this->wallet->update(['balance' => '950.0000']);
        $this->vendorInfo->update(['status' => 0]);

        $response = $this->postRefund(['mtcode' => $round]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', (string) ErrorCodeEnum::TOKEN_INVALID->value);
        $this->assertSame('950.0000', (string) $this->wallet->fresh()->balance);
    }

    /**
     * 舊系統時期（2026-09-02 前）寫入的注單，vendor_username 存的是帳號的 vendor_username（帶 TP 前綴），
     * 現行 rollout 存的是請求的 id（= 帳號 username）。兩種格式的注單都必須能被結算。
     */
    #[Group('rules/vendor-astar#R-14')]
    public function test_rollin_settles_legacy_bet_record_keyed_by_account_vendor_username(): void
    {
        $legacyKey = 'TP' . $this->username;
        $this->account->update(['vendor_username' => $legacyKey]);

        $round = 'VW1194683705';
        AstarBetRecord::forceCreate([
            'bet_id' => $round,
            'bet' => '40.00',
            'valid_bet' => '40.00',
            'win_lose' => '-40.00',
            'status' => BetRecordStatusEnum::ROLLOUT,
            'vendor_username' => $legacyKey,
            'bet_time' => now()->toDateTimeString(),
            'game_code' => $this->game->game_type,
            'session_token' => $this->gametoken,
            'info' => json_encode([]),
        ]);
        $this->wallet->update(['balance' => '960.0000']);
        $this->rotateAccountToken();

        $response = $this->postRollin($round, $this->gametoken, $this->username);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1040.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::ROLLIN->value,
        ]);
    }

    /**
     * mtcode 先當完整 round 精確比對，查無才退回三段式的第三段，避免 round 本身含 `-` 時誤指。
     */
    #[Group('rules/vendor-astar#R-19')]
    public function test_refund_prefers_exact_mtcode_match_over_third_segment(): void
    {
        $round = 'VW1-19-4217906';
        $this->seedRollout($round, '50.00');
        $this->wallet->update(['balance' => '950.0000']);

        $response = $this->postRefund(['mtcode' => $round]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1000.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::REFUND->value,
        ]);
    }
}
