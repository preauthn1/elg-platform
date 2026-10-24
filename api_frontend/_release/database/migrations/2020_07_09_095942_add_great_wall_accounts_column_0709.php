<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddGreatWallAccountsColumn0709 extends Migration
{
    protected $table_name = 'great_wall_accounts';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn($this->table_name, 'username_modified'))
        {
            DB::update("ALTER TABLE " . $this->table_name . " Add COLUMN username_modified varchar(30) AS (CONCAT('jkl_0701001', LOWER(username)));");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn($this->table_name, 'username_modified'))
        {
            Schema::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn('username_modified');
            });
        }
    }
}
