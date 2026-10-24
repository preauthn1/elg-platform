<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlertMemberIdToOrder extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('user_id')
                ->comment('帳號系統編號(Members)')
                ->change();
            $table->renameColumn('user_id', 'member_id');
        });
    }
}
