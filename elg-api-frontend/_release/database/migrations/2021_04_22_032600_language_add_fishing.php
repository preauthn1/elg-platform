<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddFishing extends Migration
{
    protected $vendor_name = 'fishing';

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
                    ->after('sky1388')
                    ->nullable()
                    ->comment($this->vendor_name . '遊戲語系');
            });

            DB::table('languages')
                ->where('id', 1)
                ->update([$this->vendor_name => 'en-us']);
            DB::table('languages')
                ->where('id', 2)
                ->update([$this->vendor_name => 'zh-cn']);
            DB::table('languages')
                ->where('id', 3)
                ->update([$this->vendor_name => 'zh-cn']);
            DB::table('languages')
                ->where('id', 4)
                ->update([$this->vendor_name => 'en-us']);
            DB::table('languages')
                ->where('id', 5)
                ->update([$this->vendor_name => 'in-id']);
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
