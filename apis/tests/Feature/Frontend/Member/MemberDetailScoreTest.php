<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Enums\Score\ScoreSourceTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Services\MemberScoreService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 會員詳情 member/detail 的 `score`（可用積分，target/tickets#4234）：
 * 前台登入後的額度都來自這支，積分也要在這裡給，前端首頁／錢包卡才拿得到；格式同 rolling_chip（4 位小數字串）。
 *
 * 必須成立：
 * 1. 沒發過積分（member_wallets 無 type 4 列）回 '0.0000'，不是 null、不是缺欄位。
 * 2. 發過積分回投影列餘額；既有欄位（balance／rolling_chip）不受影響。
 *
 * php artisan test tests/Feature/Frontend/Member/MemberDetailScoreTest.php
 */
class MemberDetailScoreTest extends TestCase
{
    private const string DETAIL = 'frontend/api/member/detail';

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent);
    }

    public function test_score_is_zero_string_when_member_never_granted(): void
    {
        $data = $this->requestApi(self::DETAIL)->assertJson(['code' => 200])->json('data');

        $this->assertArrayHasKey('score', $data);
        $this->assertSame('0.0000', $data['score']);
        $this->assertArrayHasKey('balance', $data);
        $this->assertArrayHasKey('rolling_chip', $data);
    }

    public function test_score_reflects_wallet_projection(): void
    {
        DB::transaction(fn () => app(MemberScoreService::class)->grant($this->member, '35.5', null, ScoreSourceTypeEnum::MANUAL));

        $data = $this->requestApi(self::DETAIL)->assertJson(['code' => 200])->json('data');

        $this->assertSame('35.5000', $data['score']);
        $this->assertSame($this->member->id, $data['id']);
    }
}
