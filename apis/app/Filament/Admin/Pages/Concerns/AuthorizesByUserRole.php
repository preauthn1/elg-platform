<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\User;
use Filament\Facades\Filament;

/**
 * 以登入者的 users.role（UserRoleEnum::canAccessPage）決定頁面可否進入。
 *
 * Filament 的 canAccess() 一處管兩件事：導覽選單是否列出這頁、直接輸網址是否 403，
 * 所以掛上這個 trait 就完成菜單權限，不需要另外處理 shouldRegisterNavigation。
 * 掛在頁面基底（AbstractOperationPage）與各獨立 Page 上；Resource 走 Policy（見 UserPolicy）。
 */
trait AuthorizesByUserRole
{
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->role->canAccessPage(static::class);
    }
}
