<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddPrizeContentsColumn20211004 extends Migration
{
    protected $table_name = 'prize_contents';
    protected $column_name = 'type';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->tinyInteger($this->column_name)
                    ->after('prize_id')
                    ->default(0)
                    ->comment('點數類型; 0:固定金額, 1:%數');
            });
        }
        $this->column_name = 'point_percentage';
        if (!Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->decimal($this->column_name, 4, 2)
                    ->after('point')
                    ->default(0)
                    ->comment('%數');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
        $this->column_name = 'point_percentage';
        if (Schema::hasColumn($this->table_name, $this->column_name)) {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name);
            });
        }
    }
}
