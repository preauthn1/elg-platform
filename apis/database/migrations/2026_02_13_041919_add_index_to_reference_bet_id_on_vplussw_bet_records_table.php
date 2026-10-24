<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vplussw_bet_records', function (Blueprint $table) {
            $table->index('reference_bet_id', 'reference_bet_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vplussw_bet_records', function (Blueprint $table) {
            $table->dropIndex('reference_bet_id_index');
        });
    }
};
