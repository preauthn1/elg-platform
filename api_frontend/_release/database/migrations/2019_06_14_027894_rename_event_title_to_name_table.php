<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class RenameEventTitleToNameTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::rename('event_titles', 'event_names');
        Schema::table('events', function (Blueprint $table) {
            $table->integer('agent_id')
                ->nullable()
                ->change();
            // $table->integer('prize_number')
            // ->after('point')
            // ->comment('獎項數量/次數');
            // $table->datetime('prize_expire_date')
            // ->after('prize_number')
            // ->comment('獎勵到期日');
            // $table->renameColumn('point', 'prize_id');
        });

        Schema::table('event_details', function (Blueprint $table) {
            $table->integer('event_id')
                ->after('id')
                ->comment('活動編號');
        });
    }
}
