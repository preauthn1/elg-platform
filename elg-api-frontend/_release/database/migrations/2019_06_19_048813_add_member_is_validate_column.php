<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddMemberIsValidateColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('members', function (Blueprint $table) {
            $table->integer('is_validate')
                ->after('status')
                ->default(0)
                ->comment('是否通過身份驗證');
            $table->integer('mobile_validate')
                ->after('is_validate')
                ->default(0)
                ->comment('是否通過手機驗證');
        });
    }
}
