<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertRolePermissionTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '1',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '2',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '5',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '6',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '7',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '8',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '9',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '1',
            'menu_id' => '10',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '1',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '3',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '4',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '5',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '6',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '7',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '8',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '9',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '2',
            'menu_id' => '10',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '3',
            'menu_id' => '1',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '3',
            'menu_id' => '4',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '3',
            'menu_id' => '5',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '3',
            'menu_id' => '6',
            'action' => '',
            'admin' => 0
        ));
        DB::table('role_permissions')->insert(array(
            'role_id' => '3',
            'menu_id' => '9',
            'action' => '',
            'admin' => 0
        ));
    }
}
