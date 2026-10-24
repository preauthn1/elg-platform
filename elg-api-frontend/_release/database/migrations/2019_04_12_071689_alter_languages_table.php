<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterLanguagesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->string('ag')
                ->after('code')
                ->comment('AG LANG代碼');
            ;
        });
        DB::table('languages')->where('id', 1)->update([
            'code' => 'en',
            'name' => 'English',
            'ag' => 3
        ]);
        DB::table('languages')->where('id', 2)->update([
            'code' => 'zh-tw',
            'name' => '繁體中文',
            'ag' => 2
        ]);
        DB::table('languages')->where('id', 3)->update([
            'code' => 'zh-cn',
            'name' => '简体中文',
            'ag' => 1
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {}
}
