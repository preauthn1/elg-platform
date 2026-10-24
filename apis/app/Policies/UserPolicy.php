<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * 用戶管理授權：RD（ADMIN）與 SUPERADMIN 可檢視並變更他人角色，運維（OPS）看不到這頁；
 * 新增帳號仍限 SUPERADMIN（帳號實際由 SSO 首登自動建立）。
 * 未定義的操作（刪除等）依 Laravel 預設一律拒絕。
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageUsers();
    }

    public function view(User $user, User $target): bool
    {
        return $user->canManageUsers();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->canManageUsers();
    }
}
