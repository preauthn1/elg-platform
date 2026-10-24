<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\OnebxSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\OnebxswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class OnebxSWTestCase extends TestCase
{
    protected string $baseUrl = '/external/api/sw/onebx/api';

    protected string $username = 'onebx_tester_01';

    protected string $secretKey = 'unit-test-secret-key';

    /**
     * 真實請求的 timestamp 是 JSON 數字（Dev 2026-09-15～18 實撈），不是字串
     */
    protected int $timestamp = 1789454163;

    protected Agent $agent;

    protected Member $member;

    protected Vendor $vendor;

    protected VendorInfo $vendorInfo;

    protected Game $game;

    protected OnebxswAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->member->update(['balance' => '1000.0000']);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::ONEBXSW->value);
        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->update([
            'status' => 1,
            'rate' => 1.0,
            'rolling_chip_status' => 0,
            'info' => json_encode([
                'API_URL' => 'https://onebx.test',
                'MERCHANT_ID' => 'unit-merchant',
                'SECRET_KEY' => $this->secretKey,
            ]),
        ]);

        $this->game = $this->vendor->games()->first();
        $this->game->update(['game_type' => 'OneBXSW_Lobby', 'status' => 1]);

        $this->account = OnebxswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $this->username,
            'member_id' => $this->member->id,
            'admin' => 1,
        ]);
    }

    /**
     * 依 OneBX 規格獨立重算簽名：base64(HMAC-SHA1(http_build_query(ksort(欄位)), SECRET_KEY))。
     * 真實 payload 的巢狀物件裡有 null（nick_name／tor_point），http_build_query 會把 null 丟掉，
     * 兩端同一套演算法所以仍對得上；fixture 刻意保留 null 讓這條路徑被走到。
     */
    protected function sign(array $payload, ?string $secret = [REDACTED] array
    {
        ksort($payload);
        $payload['signature'] = base64_encode(hash_hmac('SHA1', http_build_query($payload), $secret ?? $this->secretKey, true));

        return $payload;
    }

    /**
     * 照 Dev 2026-09-15 一筆真實 bet 請求的形狀（泰拳，MY 盤）：金額是數字、match_play 是巢狀物件含 null、
     * 多帶 FormRequest 沒列的 market_type。只把帳號、單號、金額換成測試值。
     */
    protected function betPayload(string $betId, float|int|string $amount, array $override = []): array
    {
        return $this->sign(array_merge([
            'amount_bet' => $amount + 0,
            'bettranstion_id' => $betId,
            'choose_bet' => 'red',
            'deduct_amount' => $amount + 0,
            'market_type' => 'Handicap',
            'match_play' => [
                'blue_player' => ['first_name' => 'เข้กบวิ่ง', 'last_name' => 'เดิมพัน 11,000 บาท', 'nick_name' => null],
                'id' => 28865,
                'league' => 'MuayTest_CMD368 15/09/2026',
                'match_time' => '2026-09-15 00:00:00',
                'point' => '0',
                'red_player' => ['first_name' => 'ไกรทอง', 'last_name' => 'เดิมพัน 11,000 บาท', 'nick_name' => null],
                'reportdate' => '2026-09-15',
                'tor_point' => '0',
            ],
            'price' => '0.73',
            'price_type' => 'MY',
            'round' => "{$this->timestamp}-{$this->username}",
            'sport_id' => 1,
            'sport_name' => 'มวย',
            'timestamp' => $this->timestamp,
            'tor_side' => 'red',
            'username' => $this->username,
        ], $override));
    }

    /**
     * settle／undo／cancel 的 item 是雙層 JSON 字串（外層 win_side ＋ data，data 自己又是 JSON 字串），
     * 照真實請求也帶 call_transaction_id；真實流量 400/400 都是一批一筆。
     */
    protected function itemPayload(array $items, string $winSide = 'red'): array
    {
        return $this->sign([
            'call_transaction_id' => '28851T1D20260915095204Win163',
            'item' => json_encode(['win_side' => $winSide, 'data' => json_encode($items)]),
            'timestamp' => $this->timestamp,
        ]);
    }

    /**
     * 真實 settle item（TPower 2026-09-17）：贏 win_side=won、credit_amount=派彩；輸 win_side=lose、credit_amount=0。
     * 都帶比分、settled_at、comm_amount 與 call_transaction_id。
     */
    protected function item(string $betId, float|int|string $creditAmount): array
    {
        $win = Common::numCompare((string) $creditAmount, 0, 4) > 0;

        return [
            'bettranstion_id' => $betId,
            'blue_score' => '0',
            'call_transaction_id' => "{$betId}-S1789678469",
            'comm_amount' => 0,
            'credit_amount' => $creditAmount + 0,
            'red_score' => '0',
            'round' => $betId,
            'settled_at' => '2026-09-18 02:49:05',
            'username' => $this->username,
            'win_side' => $win ? 'won' : 'lose',
        ];
    }

    /**
     * 真實 rollback（TPower 2026-09-17）：對存在的注單 credit_amount 等於 bet；對我方沒有的單則
     * amount_bet=0、credit_amount=0、price／tor_side 是字串 "null"（下注被拒或超時後的補送 void）。
     */
    protected function rollbackPayload(string $betId, float|int|string $creditAmount): array
    {
        $void = Common::numCompare((string) $creditAmount, 0, 4) <= 0;

        return $this->sign([
            'amount_bet' => $void ? 0 : $creditAmount + 0,
            'bettranstion_id' => $betId,
            'choose_bet' => 'red',
            'credit_amount' => $creditAmount + 0,
            'price' => $void ? 'null' : '0.97',
            'round' => $betId,
            'timestamp' => $this->timestamp,
            'tor_side' => $void ? 'null' : '1',
            'username' => $this->username,
        ]);
    }

    /**
     * 真實 undo item：credit_amount 恆為 0、win_side／比分皆 null——它不帶金額，只說「撤掉這張的結算」
     */
    protected function undoItem(string $betId): array
    {
        return [
            'bettranstion_id' => $betId,
            'call_transaction_id' => "{$betId}-U1789457242",
            'username' => $this->username,
            'win_side' => null,
            'red_score' => null,
            'blue_score' => null,
            'credit_amount' => 0,
            'round' => "{$this->timestamp}-{$this->username}",
        ];
    }

    protected function placeBet(string $betId, float|int|string $amount): TestResponse
    {
        return $this->postJson("{$this->baseUrl}/bet", $this->betPayload($betId, $amount));
    }

    protected function balance(): string
    {
        return (string) $this->member->refresh()->balance;
    }
}
