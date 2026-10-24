<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterTableAnnouncementMessagesStartTime extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('announcement_messages', function($table) {
            $table->dateTime('start_time')
                ->after('organization_id')
                ->nullable()
                ->comment('顯示起始時間');

                $table->dateTime('end_time')
                ->after('start_time')
                ->nullable()
                ->comment('顯示結束時間');
        });

    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
