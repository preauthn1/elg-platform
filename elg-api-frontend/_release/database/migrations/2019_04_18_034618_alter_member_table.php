<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterMemberTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('email', 60)
                ->nullable()
                ->change();
            $table->string('first_name', 32)
                ->nullable()
                ->change();
            $table->string('last_name', 32)
                ->nullable()
                ->change();
            $table->date('birthday')
                ->nullable()
                ->change();
            $table->string('mobile', 32)
                ->nullable()
                ->change();
            $table->integer('country_id')
                ->nullable()
                ->change();
            $table->string('zip_code', 10)
                ->nullable()
                ->change();
            $table->string('district', 32)
                ->nullable()
                ->change();
            $table->string('city', 30)
                ->nullable()
                ->change();
            $table->string('address')
                ->nullable()
                ->change();
        });
    }
}
