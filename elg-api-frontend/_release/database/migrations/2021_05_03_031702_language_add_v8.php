<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddV8 extends Migration
{
    protected $vendor_name = 'v8';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('languages', $this->vendor_name)) {
            Schema::table('languages', function (Blueprint $table) {
                $table->string($this->vendor_name)
                    ->after('npt')
                    ->nullable()
                    ->comment('v8遊戲語系');
            });

            DB::table('languages')
                ->where('id', 1)
                ->update([$this->vendor_name => 'en_us']);
            DB::table('languages')
                ->where('id', 2)
                ->update([$this->vendor_name => 'en_us']);
            DB::table('languages')
                ->where('id', 3)
                ->update([$this->vendor_name => 'en_us']);
            DB::table('languages')
                ->where('id', 4)
                ->update([$this->vendor_name => 'en_us']);
            DB::table('languages')
                ->where('id', 5)
                ->update([$this->vendor_name => 'en_us']);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('languages', $this->vendor_name)) {
            Schema::table('languages', function (Blueprint $table) {
                $table->dropColumn($this->vendor_name);
            });
        }
    }
}
