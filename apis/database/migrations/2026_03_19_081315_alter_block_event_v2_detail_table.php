<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->string('ip_block')->nullable()->default(null)->change();
            $table->string('phone_block')->nullable()->default(null)->change();
            $table->string('account_block')->nullable()->default(null)->change();
        });

        DB::table('event_v2_details')->where('ip_block', '0')->update(['ip_block' => null]);
        DB::table('event_v2_details')->where('phone_block', '0')->update(['phone_block' => null]);
        DB::table('event_v2_details')->where('account_block', '0')->update(['account_block' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('event_v2_details')->whereNotNull('ip_block')->update(['ip_block' => '1']);
        DB::table('event_v2_details')->whereNull('ip_block')->update(['ip_block' => '0']);
        DB::table('event_v2_details')->whereNotNull('phone_block')->update(['phone_block' => '1']);
        DB::table('event_v2_details')->whereNull('phone_block')->update(['phone_block' => '0']);
        DB::table('event_v2_details')->whereNotNull('account_block')->update(['account_block' => '1']);
        DB::table('event_v2_details')->whereNull('account_block')->update(['account_block' => '0']);

        SchemaMonitor::table('event_v2_details', function (Blueprint $table) {
            $table->boolean('ip_block')->default(false)->change();
            $table->boolean('phone_block')->default(false)->change();
            $table->boolean('account_block')->default(false)->change();
        });
    }
};
