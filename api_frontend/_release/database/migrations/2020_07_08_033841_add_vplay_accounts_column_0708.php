<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddVplayAccountsColumn0708 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('vplay_accounts', 'username_modified'))
        {
            DB::update("ALTER TABLE vplay_accounts Add COLUMN username_modified varchar(30) AS (CONCAT(username, '@vworld'));");
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('vplay_accounts', 'username_modified'))
        {
            Schema::table('vplay_accounts', function (Blueprint $table) {
                $table->dropColumn('username_modified');
            });
        }
    }
}
