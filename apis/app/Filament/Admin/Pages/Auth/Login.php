<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use Filament\Actions\Action;
use Filament\Auth\Http\Responses\LoginResponse;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Olympus 登入頁：一律走 Office 365 SSO，不提供帳號密碼登入（含超級管理員）。
 */
class Login extends \Filament\Auth\Pages\Login
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(session('olympus_sso_error'))
                    ->color('danger')
                    ->visible(fn (): bool => filled(session('olympus_sso_error'))),
                Actions::make([
                    Action::make('azureSso')
                        ->label(__('使用 Office 365 登入'))
                        ->icon('heroicon-o-building-office-2')
                        ->url(route('olympus.azure.redirect')),
                ])->fullWidth(),
            ]);
    }

    /**
     * 頁面不渲染帳密表單，但 Livewire 端點仍可被直接呼叫，必須一律拒絕。
     */
    public function authenticate(): ?LoginResponse
    {
        throw ValidationException::withMessages([
            'data.username' => __('filament-panels::auth/pages/login.messages.failed'),
        ]);
    }
}
