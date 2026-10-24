<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddStatusColumnToVendorAccount extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $vendors = Vendor::select('tname')->groupBy('tname')->get();
        if ($vendors->isEmpty()) {
            $vendors = require database_path('data/vendors.php');
        }
        foreach ($vendors as $key => $value) {
            if (SchemaMonitor::hasTable($value['tname'] . '_accounts') === true) {
                if (SchemaMonitor::hasColumn($value['tname'] . '_accounts', 'status') === false) {
                    SchemaMonitor::table($value['tname'] . '_accounts', function (Blueprint $table) {
                        $table->boolean('status')->default(1)->comment('狀態 0:關閉,1:正常')->after('member_id');
                    });
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $vnedor = Vendor::select('tname')->groupBy('tname')->get();
        foreach ($vnedor as $key => $value) {
            if (SchemaMonitor::hasTable($value->tname . '_accounts') === true) {
                if (SchemaMonitor::hasColumn($value->tname . '_accounts', 'status') === true) {
                    SchemaMonitor::table($value->tname . '_accounts', function (Blueprint $table) {
                        $table->dropColumn('status');
                    });
                }
            }
        }
    }
}
