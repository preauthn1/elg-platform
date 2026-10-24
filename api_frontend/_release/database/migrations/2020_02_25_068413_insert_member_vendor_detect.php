<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class InsertMemberVendorDetect extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // password = [REDACTED]
        DB::table('members')->insert(array(
            'parent' => 1,
            'username' => 'vendor_detect',
            'password' => 'DD203BC58AD6DAA5B0A2B4CD619C38C6',
            'nickname' => 'Vendor detect',
            'status' => 1,
            'is_validate' => 1,
            'mobile_validate' => 1,
            'email_validate' => 1,
            'admin' => 1
        ));
    }
}