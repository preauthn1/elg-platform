<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertOrganization extends Migration
{  
    protected $table_name = 'organizations';      

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table($this->table_name)
            ->insert(array(
                'name' => 'vworld',
                'memo' => '預設組織'                
            ));

        DB::table($this->table_name)
            ->insert(array(
                'name' => 'MMGames',
                'memo' => ''                
            ));
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}