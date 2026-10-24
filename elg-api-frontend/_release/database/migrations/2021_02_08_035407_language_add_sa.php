<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddSa extends Migration
{
    protected $table_name = 'languages';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'sa'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('sa', 10)
                    ->after('terra')
                    ->nullable()
                    ->comment('sa遊戲語系');
            });

            DB::table($this->table_name)
                ->where('id', 1)
                ->update(['sa' => 'en_US']); //英文
            DB::table($this->table_name)
                ->where('id', 2)
                ->update(['sa' => 'zh_TW']); //繁中
            DB::table($this->table_name)
                ->where('id', 3)
                ->update(['sa' => 'zh_CN']); //簡中
            DB::table($this->table_name)
                ->where('id', 4)
                ->update(['sa' => 'ms']); //馬來
            DB::table($this->table_name)
                ->where('id', 5)
                ->update(['sa' => 'id']); //印尼
            DB::table($this->table_name)
                ->where('id', 6)
                ->update(['sa' => 'vn']); //越南
            DB::table($this->table_name)
                ->where('id', 7)
                ->update(['sa' => 'th']); //泰文
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'terra'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('sa');
            });
        }
    }
}
