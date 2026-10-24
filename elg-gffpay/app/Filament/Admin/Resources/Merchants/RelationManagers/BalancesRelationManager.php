<?php

namespace App\Filament\Admin\Resources\Merchants\RelationManagers;

use App\Enums\Db\EnableEnum;
use App\Models\Currency;
use App\Models\MerchantBalance;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'balances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('models.merchant_balance.label');
    }

    public function form(Schema $schema): Schema
    {
        $operation = $schema->getOperation();

        return $schema
            ->components([
                Select::make('currency_id')
                    ->label(__('filament.fields.currency_id'))
                    ->options(Currency::all()->pluck('name', 'id'))
                    ->searchable()   // 可搜尋
                    ->disabled($operation == 'edit')
                    ->required(),
                Select::make('status')
                    ->label(__('filament.fields.status'))
                    ->options(EnableEnum::getOptions()),
                // ...
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('filament.fields.id')),
                TextColumn::make('currency.name')
                    ->label(__('filament.fields.currency_id')),
                TextColumn::make('balance')
                    ->numeric(9)
                    ->label(__('filament.fields.balance')),
                TextColumn::make('status')
                    ->label(__('filament.fields.status'))
                    ->formatStateUsing(fn (int $state) => EnableEnum::from($state)->getLabel())
                    ->color(fn (int $state) => EnableEnum::from($state)->getColor()),

                // ...
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('filament.actions.create'))
                    ->before(function (array $data, CreateAction $action): array {
                        $exists = MerchantBalance::query()
                            ->where('merchant_id', $this->ownerRecord->id)
                            ->where('currency_id', $data['currency_id'])
                            ->exists();
                        if ($exists) {
                            Notification::make()
                                ->danger()
                                ->title(__('filament.messages.currency_already_exists'))
                                ->send();
                            // 阻止繼續
                            $action->halt();
                        }

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label(__('filament.actions.edit')),
            ]);
    }
}
