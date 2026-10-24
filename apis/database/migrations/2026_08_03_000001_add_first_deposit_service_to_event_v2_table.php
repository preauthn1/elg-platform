<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::table('event_v2')->where('handler_class', 'FirstDepositService')->exists()) {
            DB::table('event_v2')->insert([
                'id' => 15,
                'name' => '首次儲值',
                'schedule' => null,
                'handler_class' => 'FirstDepositService',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('event_v2')->where('id', 15)->delete();
    }
};
