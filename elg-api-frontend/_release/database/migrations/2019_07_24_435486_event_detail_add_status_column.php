<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class EventDetailAddStatusColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('event_details', function (Blueprint $table) {
            $table->boolean('status')
                ->after('end_time')
                ->default(0)
                ->comment('狀態0:停用,1:啟用');
        });
    }
}
