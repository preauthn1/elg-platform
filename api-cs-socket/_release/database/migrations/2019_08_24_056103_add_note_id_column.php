<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddNoteIdColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('note_replies', function (Blueprint $table) {
            $table->integer('note_id')
                ->after('id')
                ->comment('記事編號');
        });
    }
}
