<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Acewin;

use App\Models\VendorInfoGame;
use App\Vendors\AcewinSW\BetErrorCodeEnum;
use App\Vendors\AcewinSW\BetRecordStatusEnum;
use App\Vendors\AcewinSW\ErrorCodeEnum;
use App\Vendors\Models\AcewinBetRecord;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * AcewinSW 下注資格檢查（target/tickets#4375）
 *
 * 資格檢查改由 bet() 在鎖住會員之後以 verifyBettability() 執行；撤單刻意不檢查會員狀態，
 * 被鎖的玩家不能再下注，但先前下出去的注仍要退。請求內容見 {@see AcewinTestCase::betPayload()}。
 */
class AcewinBettabilityTest extends AcewinTestCase
{
    /** 生產真實 round（TPower，2026-09-20） */
    private const string ROUND = '157364241104';

    #[TestDox('會員被系統鎖定時下注被擋下，餘額與注單皆不變')]
    #[Group('rules/vendor-acewin#R-12')]
    public function test_bet_is_rejected_when_member_is_locked(): void
    {
        $this->member->update(['balance' => '1000.0000', 'sys_lock' => 1]);

        $this->postJson("{$this->baseurl}/bet", $this->betPayload(self::ROUND, '0.1', '2.127'), $this->authHeaders())
            ->assertStatus(200)
            ->assertJson(['errorCode' => BetErrorCodeEnum::OTHER_ERROR->value]);

        $this->assertEquals('1000.0000', $this->member->refresh()->balance);
        $this->assertEquals(0, AcewinBetRecord::count());
    }

    #[TestDox('會員被系統鎖定時撤單仍照常退款，不因鎖定而卡住本金')]
    #[Group('rules/vendor-acewin#R-17')]
    public function test_cancel_bet_still_refunds_when_member_is_locked(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->postJson("{$this->baseurl}/bet", $this->betPayload(self::ROUND, '0.1', '0'), $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);
        $this->assertEquals('999.9000', $this->member->refresh()->balance);

        $this->member->update(['sys_lock' => 1]);

        $this->postJson("{$this->baseurl}/cancelBet", $this->cancelBetPayload(self::ROUND, '0.1'), $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);

        $this->assertEquals('1000.0000', $this->member->refresh()->balance);
        $this->assertEquals(BetRecordStatusEnum::ROLLBACK->value, AcewinBetRecord::first()->status);
    }

    #[TestDox('下注資格檢查必須排在會員上鎖之後，否則快照定在等鎖前讀不到最新狀態')]
    #[Group('rules/vendor-acewin#R-12')]
    public function test_bettability_is_verified_after_member_lock(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        DB::enableQueryLog();

        $this->postJson("{$this->baseurl}/bet", $this->betPayload(self::ROUND, '0.1', '2.127'), $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);

        $queries = collect(DB::getQueryLog())->pluck('query');
        $lockIndex = $queries->search(fn (string $q) => str_contains($q, 'from `members`') && str_contains($q, 'for update'));
        $gameIndex = $queries->search(fn (string $q) => str_contains($q, 'from `games`') && str_contains($q, '`game_type`'));

        $this->assertNotFalse($lockIndex, '下注應以 lockForUpdate 鎖住會員');
        $this->assertNotFalse($gameIndex, '資格檢查應查過 games 表');
        $this->assertGreaterThan($lockIndex, $gameIndex, '資格檢查（查 games）必須在鎖會員之後');
    }

    #[TestDox('遊戲在後台關閉但 bettable=1 時仍收單：簡化路徑只看 bettable（行為放寬）')]
    #[Group('rules/vendor-acewin#R-13')]
    public function test_bet_passes_when_game_disabled_but_still_bettable(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->game->update(['status' => 0]);
        $this->setBettable(1);

        $this->postJson("{$this->baseurl}/bet", $this->betPayload(self::ROUND, '0.1', '2.127'), $this->authHeaders())
            ->assertJson(['errorCode' => ErrorCodeEnum::OK->value]);

        $this->assertEquals(1, AcewinBetRecord::count());
    }

    #[TestDox('遊戲 bettable=0 時下注被擋下，餘額與注單皆不變')]
    #[Group('rules/vendor-acewin#R-13')]
    public function test_bet_is_rejected_when_game_is_not_bettable(): void
    {
        $this->member->update(['balance' => '1000.0000']);
        $this->setBettable(0);

        $this->postJson("{$this->baseurl}/bet", $this->betPayload(self::ROUND, '0.1', '2.127'), $this->authHeaders())
            ->assertJson(['errorCode' => BetErrorCodeEnum::OTHER_ERROR->value]);

        $this->assertEquals('1000.0000', $this->member->refresh()->balance);
        $this->assertEquals(0, AcewinBetRecord::count());
    }

    private function setBettable(int $bettable): void
    {
        VendorInfoGame::query()
            ->where('vendor_info_id', $this->vendorInfo->id)
            ->where('game_id', $this->game->id)
            ->update(['bettable' => $bettable]);
    }
}
