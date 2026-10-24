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
            ->insert(
                array(
                    array(
                        'id' => 1,
                        'name' => 'default',
                        'memo' => '預設組織'
                    ),
                    array(
                        'id' => 2,
                        'name' => 'Developer',
                        'memo' => '開發組織'
                    )
                )
            );
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