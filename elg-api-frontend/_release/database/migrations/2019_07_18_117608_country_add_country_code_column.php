<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CountryAddCountryCodeColumn extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('countries', function (Blueprint $table) {
            $table->integer('country_code')
                ->after('name')
                ->default(0)
                ->comment('國碼');
            $table->string('phone_rule')
                ->after('country_code')
                ->default(0)
                ->comment('電話號碼規則');
        });
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('need_username')
                ->after('mobile_validate')
                ->default(0)
                ->comment('忘記密碼是否需要填寫帳號0:不需要,1:需要');
        });
    }
}
