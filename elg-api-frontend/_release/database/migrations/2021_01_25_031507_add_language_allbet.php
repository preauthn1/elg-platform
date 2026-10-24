<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLanguageAllbet extends Migration
{
    protected $table_name = 'languages';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'allbet'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('allbet', 10)
                    ->after('rm')
                    ->nullable()
                    ->comment('allbet遊戲語系');
            });

            DB::table($this->table_name)
                ->where('id', 1)
                ->update(['allbet' => 'en']); //英文
            DB::table($this->table_name)
                ->where('id', 2)
                ->update(['allbet' => 'zh_TW']); //繁中
            DB::table($this->table_name)
                ->where('id', 3)
                ->update(['allbet' => 'zh_CN']); //簡中
            DB::table($this->table_name)
                ->where('id', 4)
                ->update(['allbet' => 'ms']); //馬來
            DB::table($this->table_name)
                ->where('id', 5)
                ->update(['allbet' => 'id']); //印尼
            DB::table($this->table_name)
                ->where('id', 6)
                ->update(['allbet' => 'vi']); //越南
            DB::table($this->table_name)
                ->where('id', 7)
                ->update(['allbet' => 'en']);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'allbet'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('allbet');
            });
        }
    }
}
