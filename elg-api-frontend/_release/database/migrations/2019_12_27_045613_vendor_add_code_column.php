<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAddCodeColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('code', 32)
                ->after('name')
                ->comment('遊戲商表格前綴');
        });

        DB::table('vendors')->where('id', 1)->update(['code' => 'ag']);
        DB::table('vendors')->where('id', 2)->update(['code' => 'joker']);
        DB::table('vendors')->where('id', 3)->update(['code' => 'scr']);
        DB::table('vendors')->where('id', 4)->update(['code' => 'haolong']);
        DB::table('vendors')->where('id', 5)->update(['code' => 'great_wall']);
        DB::table('vendors')->where('id', 6)->update(['code' => 'ace333']);
        DB::table('vendors')->where('id', 8)->update(['code' => 'vplay']);
        DB::table('vendors')->where('id', 9)->update(['code' => 'coke_fighting']);
        DB::table('vendors')->where('id', 10)->update(['code' => 'mega888']);
        DB::table('vendors')->where('id', 11)->update(['code' => 'xe88']);
        DB::table('vendors')->where('id', 12)->update(['code' => 'lucky']);
        DB::table('vendors')->where('id', 13)->update(['code' => 'ebet']);
        DB::table('vendors')->where('id', 14)->update(['code' => 'isb']);
        DB::table('vendors')->where('id', 15)->update(['code' => 'ygg']);
    }
}