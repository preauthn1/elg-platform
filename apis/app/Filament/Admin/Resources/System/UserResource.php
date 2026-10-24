<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System;

use App\Enums\AdminOperationEnum;
use App\Enums\UserRoleEnum;
use App\Enums\UserStatusEnum;
use App\Filament\Admin\Resources\System\UserResource\Pages\ListUser;
use App\Models\User;
use App\Services\AdminOperationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * 用戶管理：RD（ADMIN）與 SUPERADMIN 可見，運維看不到（授權見 UserPolicy）。列表唯讀，唯一可改的是角色
 * （變更角色動作；升成／降離 SUPERADMIN 只有 SUPERADMIN 能做，見 UserRoleEnum::assignableRoles()）。
 * 帳號一律由 Office 365 SSO 首登自動建立（預設運維）；超級管理員密碼異動走 tinker，不提供 UI。
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $modelLabel = '用戶';

    protected static ?string $pluralModelLabel = '用戶';

    protected static ?string $navigationLabel = '用戶管理';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('username')
                    ->label('帳號')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('nickname')
                    ->label('暱稱')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('電子信箱')
                    ->searchable(),
                TextColumn::make('azure_id')
                    ->label('SSO 綁定')
                    ->badge()
                    ->state(fn (User $record): string => filled($record->azure_id) ? '已綁定' : '未綁定')
                    ->color(fn (User $record): string => filled($record->azure_id) ? 'success' : 'gray'),
                TextColumn::make('role')
                    ->label('角色')
                    ->badge()
                    ->formatStateUsing(fn (UserRoleEnum $state): string => $state->label())
                    ->color(fn (UserRoleEnum $state): string => match ($state) {
                        UserRoleEnum::SUPERADMIN => 'warning',
                        UserRoleEnum::OPS => 'info',
                        UserRoleEnum::ADMIN => 'gray',
                    }),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => match ($state) {
                        UserStatusEnum::UNVERIFIED->value => '未認證',
                        UserStatusEnum::NORMAL->value => '正常',
                        UserStatusEnum::LOCKED->value => '鎖定',
                        default => (string) $state,
                    })
                    ->color(fn (int $state): string => $state === UserStatusEnum::NORMAL->value ? 'success' : 'danger'),
                TextColumn::make('last_login')
                    ->label('最後登入'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('changeRole')
                    ->label('變更角色')
                    ->icon('heroicon-o-user-circle')
                    // 能進這頁的已非運維（UserPolicy），再擋一次讓動作不依賴頁面授權
                    ->visible(fn (): bool => self::actor()?->canManageUsers() ?? false)
                    // 不讓自己改自己（把最後一把鑰匙鎖在門外）；ADMIN 也不能動 SUPERADMIN 的帳號
                    ->disabled(fn (User $record): bool => $record->is(self::actor()) || !self::canAssign($record->role))
                    ->fillForm(fn (User $record): array => ['role' => $record->role->value])
                    ->schema([
                        Select::make('role')
                            ->label('角色')
                            ->options(fn (): array => self::actor()?->role->assignableOptions() ?? [])
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        $from = $record->role;
                        $to = UserRoleEnum::from((int) $data['role']);
                        // 表單選項已過濾，這裡是防繞過（Livewire payload 可被改）
                        if (!self::canAssign($from) || !self::canAssign($to)) {
                            Notification::make()->danger()->title('無權指派此角色')->send();

                            return;
                        }
                        if ($from === $to) {
                            return;
                        }

                        DB::transaction(function () use ($record, $from, $to): void {
                            $record->update(['role' => $to->value]);
                            app(AdminOperationService::class)->audit(
                                operation: AdminOperationEnum::ChangeRole,
                                userId: (int) Filament::auth()->id(),
                                params: ['target_user_id' => $record->id, 'username' => $record->username, 'from' => $from->value, 'to' => $to->value],
                                output: sprintf('變更角色 %s (ID: %d)：%s → %s', $record->username, $record->id, $from->label(), $to->label()),
                            );
                        });

                        Notification::make()->success()->title('角色已變更')->body("{$record->username}：{$from->label()} → {$to->label()}")->send();
                    }),
            ]);
    }

    private static function actor(): ?User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** 操作者能否指派／變動這個角色（來源與目標角色都要過） */
    private static function canAssign(UserRoleEnum $role): bool
    {
        return in_array($role, self::actor()?->role->assignableRoles() ?? [], true);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUser::route('/'),
        ];
    }
}
