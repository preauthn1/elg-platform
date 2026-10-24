<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertTemplateC711 extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('templates')->insert(array(
            'name' => 'C711',
            'thumbnail' => '',
            'status' => 1,
            'admin' => 1
        ));
    }
}