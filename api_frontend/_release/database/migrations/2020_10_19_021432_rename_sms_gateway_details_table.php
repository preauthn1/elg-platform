<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameSmsGatewayDetailsTable extends Migration
{
    protected $from = 'sms_gateway_details';
    protected $to = 'sms_gateway_infos';
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable($this->from)){
            Schema::rename($this->from, $this->to);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable($this->to)){
            Schema::rename($this->to, $this->from);
        }
    }
}
