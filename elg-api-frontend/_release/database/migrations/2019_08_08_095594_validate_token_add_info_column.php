<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class ValidateTokenAddInfoColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('validate_tokens', function (Blueprint $table) {
            $table->string('info')
                ->after('type_id')
                ->nullable()
                ->comment('驗證的資料');
        });
    }
}
