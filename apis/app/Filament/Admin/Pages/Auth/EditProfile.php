<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Enums\AdminOperationEnum;
use App\Services\AdminOperationService;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * 自助修改密碼頁：僅開放改「自己的密碼」，須輸入目前密碼驗證本人。
 * username / 暱稱 / 角色等欄位不開放（帳號資料由 superadmin 經帳號管理維護）。
 */
class EditProfile extends BaseEditProfile
{
    public function getTitle(): string
    {
        return '修改密碼';
    }

    public static function getLabel(): string
    {
        return '修改密碼';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('currentPassword')
                    ->label('目前密碼')
                    ->password()
                    ->revealable()
                    ->autocomplete('current-password')
                    ->currentPassword(guard: Filament::getAuthGuard())
                    ->required()
                    ->dehydrated(false),
                TextInput::make('password')
                    ->label('新密碼')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->minLength(8)
                    // 與父類一致：dehydrate 時先雜湊，save() 會把雜湊值寫回 session 維持登入
                    ->dehydrateStateUsing(fn (#[SensitiveParameter] $state): string => Hash::make($state))
                    ->live(debounce: 500)
                    ->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')
                    ->label('確認新密碼')
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->required()
                    ->dehydrated(false),
            ]);
    }

    /**
     * 寫入操作紀錄（本頁唯一可存欄位是密碼，每次儲存即一次改密）
     */
    protected function afterSave(): void
    {
        $user = $this->getUser();

        app(AdminOperationService::class)->audit(
            operation: AdminOperationEnum::ChangePassword,
            userId: (int) $user->getKey(),
            params: ['username' => $user->username, 'self' => true],
            output: "自助修改密碼 {$user->username} (ID: {$user->getKey()})",
        );
    }
}
