<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * SEO 元數據的頁面代碼（agent_domain_seo_metas.page） 工單4102
 *
 * 本期只做 HOME。之後要加子頁只需補 case：後台清單固定依 cases() 列出每頁一列、
 * 前台 customized_setting.seo_meta 也依 cases() 回固定 key，兩邊自動跟上。
 */
enum SeoPageEnum: string
{
    /** 首頁 */
    case HOME = 'home';
}
