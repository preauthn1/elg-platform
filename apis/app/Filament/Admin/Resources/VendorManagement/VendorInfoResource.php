<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement;

use App\Helpers\Common;
use App\Models\VendorInfo;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VendorInfoResource extends Resource
{
    protected static ?string $model = VendorInfo::class;

    // 自選單隱藏；頁面路由也不註冊，見 getPages()
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = '遊戲商';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Name')
                    ->required(),
                TextInput::make('type')
                    ->label('Type'),
                TextInput::make('vendor_id')
                    ->label('Vendor ID')
                    ->required(),
                TextInput::make('currency')
                    ->label('Currency')
                    ->required(),
                TextInput::make('rate')
                    ->label('Rate')
                    ->numeric(),
                TextInput::make('transfer_min_amount')
                    ->label('Transfer Min Amount')
                    ->numeric(),
                TextInput::make('prepared_account_number')
                    ->label('Prepared Account Number')
                    ->numeric()
                    ->default(0)
                    ->required(),
                TextInput::make('account_prefix')
                    ->label('Account Prefix')
                    ->required(),
                Toggle::make('status')
                    ->label('Status')
                    ->default(0)
                    ->required(),
                Toggle::make('rolling_chip_status')
                    ->label('Rolling Chip Status')
                    ->default(0)
                    ->required(),
                Textarea::make('info')
                    ->json()
                    ->label('Info')
                    ->rows(10)
                    ->helperText('本商戶自己的完整設定，不會從遊戲商層的 info 繼承，缺的欄位要在這裡補齊。憑證類的值只顯示遮罩（****1234）；不改就維持原值，要更換請整段填入新值。')
                    // 原文不送進瀏覽器：顯示遮罩，儲存時由 EditVendorInfoManagement 還原沒改的憑證
                    ->formatStateUsing(fn ($state) => self::maskedInfoForDisplay($state))
                    // 回傳陣列交給 model 的 array cast 編碼；回傳 JSON 字串會被 cast 再編碼一次，存成字串而非物件
                    ->dehydrateStateUsing(fn ($state) => is_string($state) && json_validate($state) ? json_decode($state, true) : $state)
                    ->required(),
                // RichEditor::make('info')
                //     ->json(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->html()
                    ->formatStateUsing(fn ($record) => "
                        <div class='flex flex-col'>
                            <span class='block'>
                                {$record->name}
                            </span>
                            <span class='block' style='color: #b4b7c8; font-size: 12px;'>
                                ID: {$record->id}
                            </span>
                        </div>
                    "),
                TextColumn::make('vendor_id')
                    ->label('Vendor')
                    ->html()
                    ->formatStateUsing(fn ($state, $record) => "
                        <div class='flex flex-col'>
                            <span class='block'>
                                {$record->vendor->name} 
                            </span>
                            <span class='block' style='color: #b4b7c8; font-size: 12px;'> 
                                Vendor ID: {$record->vendor_id}
                            </span>
                        </div>
                    "),
                TextColumn::make('type'),
                TextColumn::make('currency'),
                TextColumn::make('prepared_account_number')
                    ->label('Prepare account'),
                TextColumn::make('account_prefix'),
                TextColumn::make('status')
                    ->formatStateUsing(fn ($state) => $state ? 'Active' : 'Inactive')
                    ->sortable()
                    ->searchable(),

            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Filter::make('vendor_id')
                    ->schema([
                        TextInput::make('vendor_id')
                            ->label('Vendor ID')
                            ->placeholder('輸入Vendor ID'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['vendor_id'], fn ($q, $vendor_id) => $q->where('vendor_id', $vendor_id));
                    }),

                SelectFilter::make('status')
                    ->options([
                        1 => 'Active',
                        0 => 'Inactive',
                    ]),

            ]);
    }

    /**
     * 解出 info 的設定陣列。model 讀出的通常已是陣列；曾被存成 JSON 字串的舊資料
     * （例："{\"KEY\":...}"）讀出是字串，要再解一層。解不出陣列回 null。
     *
     * @return array<array-key, mixed>|null
     */
    public static function decodeInfo(mixed $state): ?array
    {
        if (is_string($state) && json_validate($state)) {
            $state = json_decode($state, true);
        }

        return is_array($state) ? $state : null;
    }

    /**
     * 編輯頁顯示用：憑證遮罩後的 info；解不出設定陣列就整段不顯示，原文一律不送進瀏覽器。
     */
    private static function maskedInfoForDisplay(mixed $state): string
    {
        $info = self::decodeInfo($state);

        return $info === null
            ? '（無法解析的舊資料，內容不顯示；要修改請整段填入完整 JSON）'
            : json_encode(Common::maskVendorInfo($info), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * 列表、新增、編輯頁都不註冊路由（target/tickets#4517）；Pages 底下的類別保留。
     */
    public static function getPages(): array
    {
        return [];
    }
}
