<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddVendorColumn0730 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('remark')
                ->after('status')
                ->nullable()
                ->comment('備註');
        });
        DB::table('vendors')
            ->where('name', 'GreatWall99')
            ->update(['remark' => '有抓取遊戲的Commend，但沒有排程']);
        DB::table('vendors')
            ->where('name', 'Joker')
            ->update(['remark' => '每個月1號 03:03 抓取新遊戲']);
        DB::table('vendors')
            ->where('name', 'Ace333')
            ->update(['remark' => '每個月1號 01:03 抓取新遊戲']);
        DB::table('vendors')
            ->where('name', 'Lucky')
            ->update(['remark' => '每個月2號 01:03 抓取新遊戲']);
        DB::table('vendors')
            ->where('name', 'iSB')
            ->update(['remark' => '有抓取遊戲的Commend，但沒有排程']);
        DB::table('vendors')
            ->where('name', 'WorldMatch')
            ->update(['remark' => '每個月2號 01:03 抓取新遊戲']);
        DB::table('vendors')
            ->where('name', 'Vpower')
            ->update(['remark' => '有抓取遊戲的Commend，但沒有排程']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('remark');
        });
    }
}
