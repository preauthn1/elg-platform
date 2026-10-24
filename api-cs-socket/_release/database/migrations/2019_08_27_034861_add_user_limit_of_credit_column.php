<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddUserLimitOfCreditColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('limit_of_credit')
                ->after('thumbnail')
                ->default(0)
                ->nullable()
                ->comment('開分/洗分額度');
            $table->integer('used_credit')
                ->after('limit_of_credit')
                ->default(0)
                ->nullable()
                ->comment('已使用額度');
        });
    }
}
