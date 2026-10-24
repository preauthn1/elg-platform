<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddAgentMenuColumn0608 extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agent_menus', function (Blueprint $table) {
            $table->integer('sort')
                ->after('action')
                ->default(0)
                ->comment('排序');
        });
        
        //main menu
        DB::table('agent_menus')->where('id', 1)->update(['sort' => '10']);
        DB::table('agent_menus')->where('id', 4)->update(['sort' => '20']);
        DB::table('agent_menus')->where('id', 7)->update(['sort' => '30']);
        DB::table('agent_menus')->where('id', 11)->update(['sort' => '40']);
        DB::table('agent_menus')->where('id', 17)->update(['sort' => '50']);
        DB::table('agent_menus')->where('id', 26)->update(['sort' => '60']);
        
        //sub menu
        DB::table('agent_menus')->where('id', 19)->update(['sort' => '10']);
        DB::table('agent_menus')->where('id', 20)->update(['sort' => '20']);
        DB::table('agent_menus')->where('id', 21)->update(['sort' => '30']);
        DB::table('agent_menus')->where('id', 22)->update(['sort' => '40']);
        DB::table('agent_menus')->where('id', 31)->update(['sort' => '50']);
        DB::table('agent_menus')->where('id', 23)->update(['sort' => '60']);
        DB::table('agent_menus')->where('id', 24)->update(['sort' => '70']);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('agent_menus', function (Blueprint $table) {
    		$table->dropColumn('sort');
		});
    }
}
