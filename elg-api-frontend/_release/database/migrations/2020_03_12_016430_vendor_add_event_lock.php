<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class VendorAddEventLock extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->boolean('event_lock')
                ->after('is_slow')
                ->default(0)
                ->comment('是否可以參加鎖分活動0:不可，1:可以');
        });

        DB::table('vendors')->whereIn('id', [1, 2, 11, 12, 14, 15, 17])->update(array(
            'event_lock' => 1
        ));
    }
}