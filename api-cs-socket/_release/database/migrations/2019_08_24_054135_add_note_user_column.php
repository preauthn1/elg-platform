<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddNoteUserColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->integer('created_user')
                ->after('status')
                ->comment('建立者');
            $table->integer('updated_user')
                ->after('created_user')
                ->nullable()
                ->comment('最後更新者');
        });
    }
}
