<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('vplussw_bet_records', function (Blueprint $table) {
            $table->id();
            $table->string('bet_id', 200)->unique();
            $table->string('reference_bet_id', 200)->nullable();
            $table->string('username', 200)->index();
            $table->string('game_code', 200);
            $table->tinyInteger('status');
            $table->tinyInteger('type');
            $table->decimal('bet', 18, 4)->default(0);
            $table->decimal('valid_bet', 18, 4)->default(0);
            $table->decimal('win_lose', 18, 4)->default(0);
            $table->decimal('rolling_chips', 18, 4)->default(0);
            $table->dateTime('bet_time');
            $table->dateTime('payout_time')->index();
            $table->json('infos')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('vplussw_bet_records');
    }
};
