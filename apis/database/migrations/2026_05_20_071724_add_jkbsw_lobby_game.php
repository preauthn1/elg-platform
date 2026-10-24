<?php

declare(strict_types=1);

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('games')->insertOrIgnore([
            'vendor_id' => VendorIdEnum::JOKER_BACCARAT_SW->value,
            'type_id' => 1,
            'game_type' => 'lobby',
            'name' => 'JokerBaccarat',
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/games/JokerBaccarat/logo.png',
            'open_window' => 0,
            'status' => 1,
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('games')
            ->where('vendor_id', VendorIdEnum::JOKER_BACCARAT_SW->value)
            ->where('game_type', 'lobby')
            ->delete();
    }
};
