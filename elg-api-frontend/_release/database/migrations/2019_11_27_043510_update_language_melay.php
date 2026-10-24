<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateLanguageMelay extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        DB::table('languages')
            ->where('name', 'Melay')
            ->update(['name' => 'Melayu']);
    }
}