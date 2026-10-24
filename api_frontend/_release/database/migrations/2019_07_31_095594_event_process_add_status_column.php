<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class EventProcessAddStatusColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('event_processes', function (Blueprint $table) {
            $table->boolean('status')
                ->after('point')
                ->default(0)
                ->comment('派獎狀態0:未達標1:已達標2:已派獎');
        });
    }
}
