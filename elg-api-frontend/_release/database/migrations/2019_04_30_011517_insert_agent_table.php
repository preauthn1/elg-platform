<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertAgentTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $password = [REDACTED]'123456');
        DB::table('agents')->insert(array(
            'parent' => 0,
            'code' => '',
            'username' => 'Admin',
            'email' => 'user@example.invalid',
            'password' => $password,
            'status' => 1,
            'admin' => 1
        ));
    }
}
