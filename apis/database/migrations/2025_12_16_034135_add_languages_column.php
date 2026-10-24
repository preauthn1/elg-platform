<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $vendor_name = 'tthsw';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('languages', $this->vendor_name)) {
            SchemaMonitor::table('languages', function (Blueprint $table) {
                $table->string($this->vendor_name)->nullable()->comment($this->vendor_name . '遊戲語系');
            });
            DB::table('languages')->where('id', 1)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 2)->update([$this->vendor_name => 'zh-tw']);
            DB::table('languages')->where('id', 3)->update([$this->vendor_name => 'zh']);
            DB::table('languages')->where('id', 4)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 5)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 6)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 7)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 8)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 9)->update([$this->vendor_name => 'en']);
            DB::table('languages')->where('id', 10)->update([$this->vendor_name => 'en']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('languages', $this->vendor_name)) {
            SchemaMonitor::table('languages', function (Blueprint $table) {
                $table->dropColumn($this->vendor_name);
            });
        }
    }
};
