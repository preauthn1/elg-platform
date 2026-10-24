<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

use App\Enums\Vendor\VendorChannelEnum;

/**
 * 契約層：集成 Driver 宣告自己經由哪一家聚合商接入；未實作本介面即為直連。
 *
 * 接入通道一律由 Driver 自己宣告，禁止以「同一 Driver 掛幾個 vendor id」推導：
 * WudiSW／FumaSW 本體與免盤費商戶共用同一 Driver 但都是直連；
 * Titan 的 Connect93／Live22 各有獨立 Driver 卻同屬 TitanHub。
 *
 * 方法為 static：推導時只有 config('vendor.driver') 裡的 class 字串，不實例化 Driver
 * （實例化會連帶建構三方設定，廠商管理列表不需要也不該觸發）。
 *
 * @knowledge rules/vendor-and-channel-management
 */
interface HasAggregatorChannel
{
    /**
     * 此 Driver 接入所經由的聚合商
     */
    public static function aggregatorChannel(): VendorChannelEnum;
}
