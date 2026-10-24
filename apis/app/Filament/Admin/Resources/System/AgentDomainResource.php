<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\System;

use App\Filament\Admin\Forms\RadioWithImageTag;
use App\Filament\Admin\Resources\System\AgentDomainResource\Pages;
use App\Filament\Admin\Resources\System\AgentDomainResource\Pages\EditAgentDomain;
use App\Filament\Admin\Resources\System\AgentDomainResource\Pages\ListAgentDomain;
use App\Filament\Admin\Resources\System\AgentDomainResource\Pages\ViewAgentDomain;
use App\Models\AgentDomain;
use App\Models\Template;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
// use App\Filament\Forms\RadioWithImageTag;
use Filament\Tables\Table;

class AgentDomainResource extends Resource
{
    protected static ?string $model = AgentDomain::class;

    // 暫時自選單隱藏（頁面仍可由 URL 進入），恢復時移除此行
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    public static function form(Schema $schema): Schema
    {
        $templates = Template::select(['id', 'name', 'thumbnail'])
            ->where('status', 1)
            ->get();

        return $schema
            ->components([
                TextInput::make('domain'),
                TextInput::make('title'),
                Radio::make('status')
                    ->boolean()
                    ->inline(),
                Radio::make('need_login')
                    ->boolean()
                    ->inline(),
                FileUpload::make('logo_pc')
                    ->image()
                    ->disk('gcs')
                    ->visibility('public')
                    ->previewable(true)
                    ->downloadable(true),
                FileUpload::make('logo_mobile')
                    ->image()
                    ->disk('gcs'),
                FileUpload::make('icon')
                    ->image()
                    ->disk('gcs'),
                FileUpload::make('add_home_image')
                    ->image()
                    ->disk('gcs'),

                Tabs::make('Tabs')->tabs([
                    Tab::make('電腦版')->schema([
                        RadioWithImageTag::make('template')
                            ->label('選擇版型')
                            ->templates($templates->toArray())
                            ->columnSpanFull(),
                    ]),
                    Tab::make('手機板')->schema([
                        RadioWithImageTag::make('template')
                            ->label('選擇版型')
                            ->templates($templates->toArray())
                            ->columnSpanFull(),
                    ]),
                ])->columnSpanFull(),

                // Section::make()->schema([
                //     RadioWithImageTag::make('template')
                //         ->templates($templates->toArray())
                // ])->columns(3)
                // ->columns(3)
                // Radio::make('template')
                //     ->label('選擇版型')
                //     ->options(
                //         $templates->pluck('id', 'id')->toArray()
                //     )
                //     ->descriptions(
                //         $templates->mapWithKeys(fn ($template) => [
                //             $template->id =>
                //               '<img src="' . $template->thumbnail . '" class="w-32 h-20 rounded shadow border" />'
                //         ])->toArray()
                //     )
                //     ->extraAttributes(['class' => 'space-y-4'])
                //     ->columnSpanFull()
                //     ->html()
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->searchable(),
                TextColumn::make('domain')->sortable()->searchable(),
                TextColumn::make('title')->sortable()->searchable(),
                TextColumn::make('agent.username')->sortable()->searchable(),
                ImageColumn::make('logo_pc')->sortable()->searchable(),
                TextColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('updated_at')
                    ->formatStateUsing(fn ($state) => Carbon::parse($state)->toFrontendTime()->format('Y-m-d H:i:s'))
                    ->sortable()
                    ->searchable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Grid::make(2)->schema([
                    Group::make([
                        TextEntry::make('id')
                            ->size(TextSize::Large),
                        TextEntry::make('title'),
                        TextEntry::make('updated_at')
                            ->formatStateUsing(
                                fn ($state) => Carbon::parse($state)->toFrontendTime()->format('Y-m-d H:i:s')
                            ),
                    ]),
                    Group::make([
                        TextEntry::make('domain'),
                        TextEntry::make('agent.username'),
                        TextEntry::make('status')
                            ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'danger'),
                    ]),
                ]),
                ImageEntry::make('logo_pc'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentDomain::route('/'),
            // 'create' => Pages\CreateAgentDomain::route('/create'),
            'view' => ViewAgentDomain::route('/{record}'),
            'edit' => EditAgentDomain::route('/{record}/edit'),
        ];
    }
}
