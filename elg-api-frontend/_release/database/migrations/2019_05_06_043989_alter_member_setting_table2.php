<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterMemberSettingTable2 extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('member_settings', function (Blueprint $table) {
            $table->dropColumn([
                'language_id',
                'timezone_id'
            ]);
        });
    }
}
