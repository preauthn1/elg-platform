<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddVendorToGames extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('ag_games', 'games');
        Schema::table('games', function (Blueprint $table) {
            $table->integer('vendor_id')
                ->after('id')
                ->default(0)
                ->comment('遊戲商系統編號');
            ;
        });
        DB::table('games')->update([
            'vendor_id' => 1
        ]);
    }
}
