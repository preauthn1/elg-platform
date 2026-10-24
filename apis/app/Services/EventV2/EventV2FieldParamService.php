<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldParamKindEnum;

/**
 * 把 handler 宣告的「哪一格會讀哪些參數」（AbstractEventV2::fieldParams()）翻成後台彈窗要的本地化結構。
 *
 * 說明文字讀 resources/lang/{locale}/event_v2.php 的 params 目錄，語系跟請求的 Accept-Language 走
 * （Agent DetectLanguage 已 setLocale），沒有該語系由 Laravel fallback 退回英文。
 * 目錄缺條目時不拋錯，退回「只有名稱」的條目——測試 FieldParamsCatalogTest 負責在合併前擋漏，
 * 線上寧可少一段說明也不要整個活動類型清單 500。
 */
final class EventV2FieldParamService
{
    private const string HANDLER_NAMESPACE = 'App\\Services\\EventV2\\';

    /**
     * @return array<string, array{main: array<string, mixed>, score?: array<string, mixed>}>|null handler 不存在或不是活動 handler 回 null
     */
    public function forHandler(?string $handlerClass): ?array
    {
        if ($handlerClass === null || $handlerClass === '') {
            return null;
        }

        $class = self::HANDLER_NAMESPACE . $handlerClass;
        if (!class_exists($class) || !is_subclass_of($class, AbstractEventV2::class)) {
            return null;
        }

        $out = [];
        foreach ($class::fieldParams() as $field => $entry) {
            $out[$field] = ['main' => $this->localize($entry['kind'], $entry['items'])];
            if (isset($entry['score'])) {
                $out[$field]['score'] = $this->localize($entry['score']['kind'], $entry['score']['items']);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array{kind: string, items: list<array{name: string, type: string|null, description: string, example: string}>}
     */
    private function localize(EventV2FieldParamKindEnum $kind, array $ids): array
    {
        if ($kind === EventV2FieldParamKindEnum::NONE) {
            return ['kind' => $kind->value, 'items' => []];
        }

        $items = [];
        foreach ($ids as $id) {
            $entry = __("event_v2.params.{$kind->value}.{$id}");
            $entry = is_array($entry) ? $entry : [];

            $items[] = [
                'name' => (string) ($entry['name'] ?? $id),
                'type' => isset($entry['type']) ? (string) $entry['type'] : null,
                'description' => (string) ($entry['description'] ?? ''),
                'example' => (string) ($entry['example'] ?? ''),
            ];
        }

        return ['kind' => $kind->value, 'items' => $items];
    }
}
