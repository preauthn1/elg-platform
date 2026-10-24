<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 排程 marker：宣告遊戲商加入 create_vendor_account 自動排程補帳號池；
 * 降級為 SupportsAccountCreation 即退出排程，仍可 --vendor-id 手動補建。
 *
 * - 自動排程的遊戲商範圍由 config('vendor.driver') 中有實作本介面的 driver 決定，
 *   不以 vendor id 分界線對照。
 * - ⚠️ 聚合遊戲商（同一 Driver 掛多個 vendor id）標記後旗下所有 vendor id 一併納入。
 * - ⚠️ 同一遊戲商禁止兩端同時補池（會互撞流水號）：新標記的 driver 上線前，
 *   RD-Studio 對應的 create_*_account 排程必須先下線。
 *
 * @knowledge rules/vendor-account-pool
 */
interface ShouldScheduleAccountCreation extends SupportsAccountCreation {}
