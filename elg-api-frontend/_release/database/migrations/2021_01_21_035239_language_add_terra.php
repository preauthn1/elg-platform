<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddTerra extends Migration
{
    protected $table_name = 'languages';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'terra'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('terra', 10)
                    ->after('rm')
                    ->nullable()
                    ->comment('terra遊戲語系');
            });

            DB::table($this->table_name)
                ->where('id', 1)
                ->update(['terra' => 'en-US']); //英文
            DB::table($this->table_name)
                ->where('id', 2)
                ->update(['terra' => 'zh-TW']); //繁中
            DB::table($this->table_name)
                ->where('id', 3)
                ->update(['terra' => 'zh-CN']); //簡中
            DB::table($this->table_name)
                ->where('id', 4)
                ->update(['terra' => 'en-US']); //馬來
            DB::table($this->table_name)
                ->where('id', 5)
                ->update(['terra' => 'en-US']); //印尼
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
                $table->dropColumn('terra');
            });
        }
    }
}
