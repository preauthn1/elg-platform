<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAgentsHierarchyPath extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function($table) {
            $table->string('hierarchy_path' , 191)
                ->after('hierarchy')
                ->nullable()
                ->index()
                ->comment('新版hierarchy結構，存成路徑式的數值');
        });

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
