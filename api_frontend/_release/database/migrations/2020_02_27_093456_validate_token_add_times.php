<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ValidateTokenAddTimes extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('validate_tokens', function (Blueprint $table) {
            $table->integer('times')
                ->after('status')
                ->default(1)
                ->comment('簡訊發送次數');
        });
    }
}