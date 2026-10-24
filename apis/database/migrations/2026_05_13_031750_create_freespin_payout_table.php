<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $table_name = 'freespin_payout';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable($this->table_name)) {
            return;
        }
        Schema::create($this->table_name, function (Blueprint $table) {
            $table->bigInteger('Id')->primary();
            $table->dateTime('Time')->index('idx_time');
            $table->decimal('BeginV', 18, 4);
            $table->decimal('Bet', 18, 4);
            $table->decimal('Win', 18, 4);
            $table->decimal('Coin', 18, 4);
            $table->bigInteger('TransferId');
            $table->tinyInteger('G_Type');
            $table->string('Name', 100)->nullable();
            $table->string('ipAddress', 45);
            $table->string('currency', 50);
            $table->dateTime('SuccessTime');
            $table->tinyInteger('Flag');
            $table->string('Uid', 100)->index('idx_uid');
            $table->bigInteger('UserId')->index('idx_userid');
            $table->string('des', 100);
            $table->string('OpeName', 50);
            $table->bigInteger('SuccessTransferId')->nullable();
            $table->string('Mode', 50);
            $table->tinyInteger('Waiting');
            $table->bigInteger('RoundId')->index('idx_roundid');
            $table->tinyInteger('freewin');
            $table->tinyInteger('lastfree');
            $table->decimal('freetotalwin', 18, 4);
            $table->bigInteger('freewin_roundId');
            $table->tinyInteger('isGetFree');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->table_name);
    }
};
