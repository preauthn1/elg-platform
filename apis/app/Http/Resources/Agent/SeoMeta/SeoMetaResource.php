<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\SeoMeta;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SEO 元數據 工單4102
 *
 * 資料來源為 AgentSeoMetaService::list() 的 items（陣列，已依 SeoPageEnum 補齊每頁一筆），非 model。
 *
 * @property array{page: string, title: string, description: string, keywords: string} $resource
 */
class SeoMetaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var string 頁面代碼 home:首頁
             *
             * @example "home"
             */
            'page' => (string) $this->resource['page'],
            /**
             * @var string 頁面標題（<title>）
             *
             * @example "VWorld 線上娛樂城"
             */
            'title' => (string) $this->resource['title'],
            /**
             * @var string 頁面描述（<meta name="description">）
             *
             * @example "提供體育、真人、電子等多元遊戲"
             */
            'description' => (string) $this->resource['description'],
            /**
             * @var string 關鍵字（<meta name="keywords">），逗號分隔
             *
             * @example "娛樂城,體育,真人"
             */
            'keywords' => (string) $this->resource['keywords'],
        ];
    }
}
