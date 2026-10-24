<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\General\ContextKeyEnum;
use App\Enums\LanguageEnum;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Services\AgentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;

/**
 * 基本資訊（前台頁尾政策文案） 工單4104
 *
 * 五個項目各由一組設定構成：顯示開關（boolean）＋ 多語系內容（language_text），
 * 定義掛在個性化組別的「推廣設置」（66000）底下，由營運在後台維護。
 *
 * 內容取值：**當前語系 → 英文 → 空字串**，直接讀 agent_customized_settings，
 * 不走 AgentService::getAgentSettings()。後者對 language_text 型別在無值時會
 * fallback 到 language_texts 的欄位標籤（該欄位在後台叫什麼名字），對「錢包文字」
 * 這類短標籤設定是正確行為，但套在政策長文上會讓前台收到 "常見問題"／"FAQ"
 * 這種欄位名當內容，顯示成一頁只有四個字的空白頁。
 *
 * 開關仍沿用 getAgentSettings()——boolean 型別不吃 language_text fallback，
 * 無此問題。
 */
class BasicInfoService
{
    /**
     * 項目定義：key => [顯示開關 setting_id, 內容 setting_id]
     *
     * key 為前台辨識用的語意代號，順序即為頁尾的呈現順序。
     */
    private const array ITEMS = [
        'about_us' => [
            AgentCustomizedSettingKeyEnum::BASIC_INFO_ABOUT_US_VISIBLE,
            AgentCustomizedSettingKeyEnum::BASIC_INFO_ABOUT_US_CONTENT,
        ],
        'terms' => [
            AgentCustomizedSettingKeyEnum::BASIC_INFO_TERMS_VISIBLE,
            AgentCustomizedSettingKeyEnum::BASIC_INFO_TERMS_CONTENT,
        ],
        'faq' => [
            AgentCustomizedSettingKeyEnum::BASIC_INFO_FAQ_VISIBLE,
            AgentCustomizedSettingKeyEnum::BASIC_INFO_FAQ_CONTENT,
        ],
        'privacy' => [
            AgentCustomizedSettingKeyEnum::BASIC_INFO_PRIVACY_VISIBLE,
            AgentCustomizedSettingKeyEnum::BASIC_INFO_PRIVACY_CONTENT,
        ],
        'responsible_gaming' => [
            AgentCustomizedSettingKeyEnum::BASIC_INFO_RESPONSIBLE_GAMING_VISIBLE,
            AgentCustomizedSettingKeyEnum::BASIC_INFO_RESPONSIBLE_GAMING_CONTENT,
        ],
    ];

    public function __construct(
        private readonly AgentService $agentService,
    ) {}

    /**
     * 由既有的設定陣列算出各項是否呈現
     *
     * 供 ConfigService 下發 template 用——該處已取過 agent settings，
     * 開關沿用其結果；內容則與 getContents 走同一份取值（當前語系→英文），
     * 兩支接口的判定才會一致：旗標說有、點開卻空的情況不該發生。
     *
     * @param  array  $settings  AgentService::getAgentSettings() 的結果
     * @return array<string, bool>
     */
    public function resolveFlags(array $settings, Agent $agent): array
    {
        $contents = $this->fetchContents($agent);
        $flags = [];

        foreach (self::ITEMS as $key => [$visibleKey, $contentKey]) {
            $flags[$key] = $this->isVisible($settings, $visibleKey, $contents[$contentKey] ?? '');
        }

        return $flags;
    }

    /**
     * 取各項內容
     *
     * 五個項目一律回傳，未填內容者 content 為空字串——前端依固定的 key 取值，
     * 少了欄位會取不到而出錯，故不因內容空就抽掉整項。
     *
     * 內容取值為「當前語系 → 英文 → 空字串」，永遠不會回欄位標籤那類非內容文字；
     * 頁尾要不要放該項入口，前端依 template 的 basic_info 旗標判斷。
     *
     * @return array<int, array{key: string, content: string}>
     */
    public function getContents(Agent $agent): array
    {
        $settings = $this->agentService->getAgentSettings($agent);
        $contents = $this->fetchContents($agent);

        $items = [];

        foreach (self::ITEMS as $key => [$visibleKey, $contentKey]) {
            $content = $contents[$contentKey] ?? '';

            $items[] = [
                'key' => $key,
                // 開關關閉者不下發內容：後台已停用，內容不應外流到前台
                'content' => $this->isVisible($settings, $visibleKey, $content) ? $content : '',
            ];
        }

        return $items;
    }

    /**
     * 五個項目的內容，依「當前語系 → 英文 → 空字串」取值
     *
     * 一次撈完五個 key 的所有語系列，在 PHP 端挑選，避免逐項查詢。
     * 未填的語系不會有列、或列的 value 為空字串，兩者都視為未填。
     *
     * @return array<int, string> setting_key => 內容
     */
    private function fetchContents(Agent $agent): array
    {
        $languageId = (int) (Context::getHidden(ContextKeyEnum::LANGUAGE_ID->value) ?? LanguageEnum::EN->value);
        $contentKeys = array_map(fn (array $item) => $item[1], array_values(self::ITEMS));

        $cacheKey = CacheKeys::basicInfoContents($agent->customized_setting_group_id, $languageId);

        /** @var array<int, string> */
        return Cache::remember($cacheKey, CacheTtl::ONE_MINUTE->value, function () use ($agent, $languageId, $contentKeys) {
            $rows = AgentCustomizedSetting::query()
                ->where('agent_group_id', $agent->customized_setting_group_id)
                ->whereIn('setting_key', $contentKeys)
                ->whereIn('sub_category', array_unique([$languageId, LanguageEnum::EN->value]))
                ->get(['setting_key', 'sub_category', 'value']);

            $contents = [];

            foreach ($contentKeys as $contentKey) {
                $pick = fn (int $language) => trim((string) ($rows
                    ->first(fn ($row) => (int) $row->setting_key === $contentKey
                        && (int) $row->sub_category === $language)?->value ?? ''));

                // 當前語系沒填就退到英文；英文也沒填即為空，由 isVisible 擋掉不下發
                $contents[$contentKey] = $pick($languageId) ?: $pick(LanguageEnum::EN->value);
            }

            return $contents;
        });
    }

    /**
     * 該項目是否要呈現
     *
     * 開關開啟且內容非空才算——開關開了卻沒填內容，前台會顯示空白頁，
     * 對玩家沒有意義，故一律視為不顯示。
     */
    private function isVisible(array $settings, int $visibleKey, string $content): bool
    {
        if ((int) ($settings[$visibleKey] ?? 0) !== 1) {
            return false;
        }

        return $content !== '';
    }
}
