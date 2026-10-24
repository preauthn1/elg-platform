<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertUsersTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $password = [REDACTED]'123456');
        DB::table('users')->insert(array(
            'parent' => 0,
            'username' => 'Admin',
            'email' => 'user@example.invalid',
            'password' => $password,
            'mobile' => '0912345678',
            'status' => 1,
            'admin' => 1
        ));
    }
}
