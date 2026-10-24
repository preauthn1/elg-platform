<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

class AddEventPayoutCancelsColumn20250722 extends Migration
{
    protected $table_name = 'event_payout_cancels';

    protected $column_name_1 = 'prize_member_id';

    protected $column_name_2 = 'prize_content_id';

    protected $column_name_3 = 'from_member_id';

    protected $column_name_4 = 'status';

    protected $column_name_5 = 'operator';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!SchemaMonitor::hasColumn($this->table_name, $this->column_name_1)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name_1)->after('event_id')->default(0)->comment('prize_members.id');
            });
        }

        if (!SchemaMonitor::hasColumn($this->table_name, $this->column_name_2)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name_2)->after($this->column_name_1)->default(0)->comment('prize_contents.id');
            });
        }

        if (!SchemaMonitor::hasColumn($this->table_name, $this->column_name_3)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name_3)->after('order_id')->default(0)->comment('orders.id');
            });
        }

        if (!SchemaMonitor::hasColumn($this->table_name, $this->column_name_4)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name_4)->after('remark')->default(0)->comment('狀態;0:未派發、 1: 已補派');
            });
        }

        if (!SchemaMonitor::hasColumn($this->table_name, $this->column_name_5)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->integer($this->column_name_5)->after($this->column_name_4)->default(0)->comment('操作人');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (SchemaMonitor::hasColumn($this->table_name, $this->column_name_1)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_1);
            });
        }

        if (SchemaMonitor::hasColumn($this->table_name, $this->column_name_2)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_2);
            });
        }

        if (SchemaMonitor::hasColumn($this->table_name, $this->column_name_3)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_3);
            });
        }

        if (SchemaMonitor::hasColumn($this->table_name, $this->column_name_4)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_4);
            });
        }

        if (SchemaMonitor::hasColumn($this->table_name, $this->column_name_5)) {
            SchemaMonitor::table($this->table_name, function (Blueprint $table) {
                $table->dropColumn($this->column_name_5);
            });
        }
    }
}
