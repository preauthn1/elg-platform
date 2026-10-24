<?php

declare(strict_types=1);

namespace App\Enums;

use App\Filament\Admin\Pages\Operations\CreateVendorAccount;
use App\Filament\Admin\Pages\Operations\FetchBetRecord;
use App\Filament\Admin\Pages\Operations\OperationLogs;
use App\Filament\Admin\Pages\Operations\RedisMemoryAnalyze;
use App\Filament\Admin\Pages\Operations\SummaryBetRecord;
use App\Filament\Admin\Pages\Operations\TransferMoneyBack;
use App\Filament\Admin\Pages\Operations\UpdateGameList;
use App\Filament\Admin\Pages\Queries\BetRecordCorrection;
use App\Filament\Admin\Pages\Queries\VendorAccountPool;

/**
 * Olympus 後台帳號角色（users.role）。
 *
 * 菜單權限由 {@see self::canAccessPage()} 決定，Filament 的 canAccess() 同時控制導覽顯示與直接輸網址（403）；
 * 帳號管理另由 UserPolicy 限 SUPERADMIN。角色只影響 Olympus，與代理後台的 AgentPermission 無關。
 */
enum UserRoleEnum: int
{
    /** 一般管理員（RD）：全部菜單 */
    case ADMIN = 1;
    /** 超級管理員：全部菜單，另可密碼登入、管理帳號與角色 */
    case SUPERADMIN = 2;
    /** 運維：只能進 OPS_ALLOWED_PAGES 明列的頁面；SSO 首登自動建的帳號預設此角色 */
    case OPS = 3;

    /**
     * 運維可進入的頁面（Filament Page class 名）——接受清單，沒列的一律不給，新頁面預設對運維關閉。
     * 目前不在清單內的：資料表加索引／加欄位（SchemaOperation，動 schema）、帳號管理（UserPolicy 另擋）。
     *
     * @var list<class-string>
     */
    private const array OPS_ALLOWED_PAGES = [
        UpdateGameList::class,
        FetchBetRecord::class,
        SummaryBetRecord::class,
        CreateVendorAccount::class,
        TransferMoneyBack::class,
        RedisMemoryAnalyze::class,
        OperationLogs::class,
        BetRecordCorrection::class,
        VendorAccountPool::class,
    ];

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::SUPERADMIN => 'Super Admin',
            self::OPS => '運維',
        };
    }

    /**
     * 該角色能否進入指定 Olympus 頁面（Filament Page class 名）
     *
     * @param  class-string  $page
     */
    public function canAccessPage(string $page): bool
    {
        return match ($this) {
            self::ADMIN, self::SUPERADMIN => true,
            self::OPS => in_array($page, self::OPS_ALLOWED_PAGES, true),
        };
    }

    /**
     * @return array<int, string> value => label，給表單 Select 用
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * 操作者能把別人指派成哪些角色：SUPERADMIN 全部；ADMIN 只能在 ADMIN／OPS 之間切
     * （不能把人升成 SUPERADMIN，那等於自己拿到密碼登入與建帳號權）；OPS 進不了用戶管理，回空。
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::SUPERADMIN => self::cases(),
            self::ADMIN => [self::ADMIN, self::OPS],
            self::OPS => [],
        };
    }

    /**
     * @return array<int, string> value => label，assignableRoles() 的 Select 版
     */
    public function assignableOptions(): array
    {
        $options = [];
        foreach ($this->assignableRoles() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
