<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddReadMessageRoomIdColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('message_reads', function (Blueprint $table) {
            $table->integer('room_id')
                ->after('id')
                ->comment('聊天室編號');
        });
    }
}
