<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AlterAgentNullTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('mobile', 30)
                ->nullable()
                ->change();
            $table->integer('country_id')
                ->nullable()
                ->change();
            $table->string('zip_code', 30)
                ->nullable()
                ->change();
            $table->string('district', 30)
                ->nullable()
                ->change();
            $table->string('city', 30)
                ->nullable()
                ->change();
            $table->string('address', 128)
                ->nullable()
                ->change();
            $table->boolean('frontend_register')
                ->nullable()
                ->change();
        });
    }
}
