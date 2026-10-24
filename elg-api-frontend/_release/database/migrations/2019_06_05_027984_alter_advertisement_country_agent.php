<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAdvertisementCountryAgent extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->string('country_id')
                ->nullable()
                ->comment('國家系統編號(Json格式)')
                ->change();
            $table->string('agent_id')
                ->nullable()
                ->comment('代理系統編號(Json格式)')
                ->change();
        });
    }
}
