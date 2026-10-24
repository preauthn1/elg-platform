<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddLanguageDg extends Migration
{
    protected $table_name = 'languages';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'dg'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('dg', 10)
                    ->after('ct')
                    ->nullable()
                    ->comment('dg遊戲語系');
            });

            DB::table($this->table_name)
                ->where('id', 1)
                ->update(['ct' => 'en']); //英文
            DB::table($this->table_name)
                ->where('id', 2)
                ->update(['ct' => 'tw']); //繁中
            DB::table($this->table_name)
                ->where('id', 3)
                ->update(['ct' => 'cn']); //簡中
            DB::table($this->table_name)
                ->where('id', 4)
                ->update(['ct' => 'en']); //馬來
            DB::table($this->table_name)
                ->where('id', 5)
                ->update(['ct' => 'en']); //印尼
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'dg'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('dg');
            });
        }
    }
}
