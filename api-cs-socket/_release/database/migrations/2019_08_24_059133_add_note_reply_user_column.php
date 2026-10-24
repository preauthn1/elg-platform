<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddNoteReplyUserColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('note_replies', function (Blueprint $table) {
            $table->string('updated_user')
                ->after('updated_at')
                ->comment('最後編輯者');
        });
    }
}
