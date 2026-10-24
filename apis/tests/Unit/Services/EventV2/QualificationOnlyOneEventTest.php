<?php

declare(strict_types=1);

namespace Tests\Unit\Services\EventV2;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\LanguageEnum;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Services\EventV2\DailyFreeService;
use Database\Seeders\LanguageSeeder;
use Tests\TestCase;

/**
 * AbstractEventV2::qualificationOnlyOneEventIssue() 與 validate() 的 ONLY_ONE_EVENT
 * 路徑必須對齊：同一會員若已 JOIN 某個 event_v2_detail，再檢查同一 event_v2 底下的
 * 另一個 detail 也要回報 ONLY_ONE_EVENT。
 *
 * 對應修正：將 helper 的比對欄位從 event_v2_id 改為 event_v2_detail_id，
 * 對齊 validate() (AbstractEventV2.php:89)。
 *
 * php artisan test tests/Unit/Services/EventV2/QualificationOnlyOneEventTest.php
 */
class QualificationOnlyOneEventTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private DailyFreeService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        app()->setLocale('en');

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->service = app(DailyFreeService::class);
    }

    /**
     * event_v2_id=2 (DailyFreeService) 是 ValidateBlockTest 與本檔共用的測試 fixture，
     * 避免新增 EventV2 row 對應 handler_class。
     */
    private function createDetail(): EventV2Detail
    {
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 2,
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => '10',
            'balance_formula' => null,
            'withdraw_formula' => null,
            'flow_formula' => null,
        ]);

        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::ZH_TW->value,
            'name' => 'Test Event',
            'image' => 'image',
            'reward_name' => 'Test',
            'content' => 'Test content',
        ]);

        return $detail;
    }

    private function joinDetail(EventV2Detail $detail): void
    {
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_qualification_reports_only_one_event_when_joined_other_detail_under_same_event_v2(): void
    {
        // 同一 event_v2_id=2 下兩個不同的 detail
        $joinedDetail = $this->createDetail();
        $targetDetail = $this->createDetail();

        $this->joinDetail($joinedDetail);

        $issues = $this->service->getQualificationIssues($targetDetail, $this->member);

        $expected = (string) __('event_v2.validation.' . EventV2ValidateCodeEnum::ONLY_ONE_EVENT->value);
        $this->assertContains($expected, $issues);
    }

    public function test_validate_and_qualification_agree_on_only_one_event(): void
    {
        $joinedDetail = $this->createDetail();
        $targetDetail = $this->createDetail();

        $this->joinDetail($joinedDetail);

        $validateResult = $this->service->validate(
            $targetDetail->id,
            $this->member,
        );
        $this->assertTrue($validateResult->isFailed());
        $this->assertSame(EventV2ValidateCodeEnum::ONLY_ONE_EVENT, $validateResult->code);

        $issues = $this->service->getQualificationIssues($targetDetail, $this->member);
        $expected = (string) __('event_v2.validation.' . EventV2ValidateCodeEnum::ONLY_ONE_EVENT->value);
        $this->assertContains($expected, $issues);
    }

    public function test_qualification_does_not_report_only_one_event_when_no_existing_join(): void
    {
        $this->createDetail();
        $targetDetail = $this->createDetail();

        $issues = $this->service->getQualificationIssues($targetDetail, $this->member);

        $only = (string) __('event_v2.validation.' . EventV2ValidateCodeEnum::ONLY_ONE_EVENT->value);
        $this->assertNotContains($only, $issues);
    }
}
