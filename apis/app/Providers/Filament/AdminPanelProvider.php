<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\SetOlympusLocale;
use Filament\Forms\Components\DatePicker;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\URL;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * 後台所有日期時間的顯示時區：操作者在台北，DB 一律存 UTC、只在顯示層換算。
     * 表格／資訊欄的 dateTime() 由 boot() 的 FilamentTimezone 統一套用；Blade 直印 Carbon 不會自動換算，
     * 走 AbstractOperationPage::displayTime()。對帳頁時區選單的預設值（DEFAULT_TIMEZONE）也對齊這裡。
     */
    public const string DISPLAY_TIMEZONE = '+08:00';

    /**
     * /olympus 僅隨 Agent_API 實例部署，對齊 bootstrap/app.php 的 APP_NAME 路由分流：
     * 其他實例（Frontend_API / External_API）不註冊 panel，路由不存在（404）。
     * APP_NAME=Laravel（本機／測試站全註冊）維持可用。
     */
    public function register(): void
    {
        if (!in_array(config('app.name'), ['Laravel', 'Agent_API'], true)) {
            return;
        }

        parent::register();
    }

    public function panel(Panel $panel): Panel
    {
        if (config('app.env') == 'local') {
            URL::forceScheme('http');
        } else {
            URL::forceScheme('https');
        }

        return $panel
            ->default()
            ->id('olympus')
            ->path('olympus')
            ->brandName('Olympus-' . config('custom.site_name'))
            ->login(
                \App\Filament\Admin\Pages\Auth\Login::class,
            )
            ->profile(\App\Filament\Admin\Pages\Auth\EditProfile::class, isSimple: false)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->navigationItems([
                // 入口不做 gate 過濾（進 panel 即可見）；實際訪問仍由 Horizon 授權把關
                NavigationItem::make('Horizon')
                    ->url(fn (): string => url(config('horizon.path')), shouldOpenInNewTab: true)
                    ->icon('heroicon-o-queue-list')
                    ->group('System'),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken:[REDACTED],
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // persistent：Livewire 的後續請求（翻頁、篩選、儲存）也要套用，錯誤訊息才會是中文
            ->middleware([SetOlympusLocale::class], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
            ])
            ->authGuard('admin')
            ->sidebarCollapsibleOnDesktop();
    }

    public function boot(): void
    {
        // TextColumn／TextEntry 的 dateTime() 與 DateTimePicker 都經這裡換算，不必逐欄指定時區
        FilamentTimezone::set(self::DISPLAY_TIMEZONE);

        // 全站日期欄位選完就收起月曆；DateTimePicker 不套用（後面還要選時間）
        DatePicker::configureUsing(fn (DatePicker $component): DatePicker => $component->closeOnDateSelection());
    }
}
