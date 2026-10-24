<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        SchemaMonitor::create('event_v2_bonus_codes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('event_v2_user_join_id')->index()->comment('event_v2_user_join.id');
            $table->unsignedInteger('vendor_id')->index()->comment('vendors.id');
            $table->string('bonus_code')->index()->comment('活動優惠代碼');

            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::dropIfExists('event_v2_bonus_codes');
    }
};
