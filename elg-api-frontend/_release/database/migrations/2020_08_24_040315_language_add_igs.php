<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class LanguageAddIgs extends Migration
{
    protected $table_name = 'languages';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'mt'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('mt', 10)
                    ->after('target')
                    ->nullable()
                    ->comment('mt遊戲語系');
            });
        }

        if (!Schema::hasColumn($this->table_name, 'igs'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->string('igs', 10)
                    ->after('mt')
                    ->nullable()
                    ->comment('igs遊戲語系');
            });

            DB::table($this->table_name)
                ->where('id', 1)
                ->update(['igs' => '2']); //英文
            DB::table($this->table_name)
                ->where('id', 2)
                ->update(['igs' => '1']); //繁中
            DB::table($this->table_name)
                ->where('id', 3)
                ->update(['igs' => '1']); //簡中
            DB::table($this->table_name)
                ->where('id', 4)
                ->update(['igs' => '3']); //馬來
            DB::table($this->table_name)
                ->where('id', 5)
                ->update(['igs' => '2']); //印尼
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'igs'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('igs');
            });
        }
    }
}
