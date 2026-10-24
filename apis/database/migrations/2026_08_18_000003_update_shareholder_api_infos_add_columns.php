<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shareholder_api_infos', function (Blueprint $table) {
            if (!Schema::hasColumn('shareholder_api_infos', 'type')) {
                $table->tinyInteger('type')->default(0)->comment('1=連桶主站, 2=連桶子站')->after('id');
            }
            if (!Schema::hasColumn('shareholder_api_infos', 'source')) {
                $table->tinyInteger('source')->default(0)->comment('0=內部系統, 1=外部系統')->after('type');
            }
            if (!Schema::hasColumn('shareholder_api_infos', 'currency_id')) {
                $table->integer('currency_id')->default(1)->comment('幣別')->after('foreign_agent_id');
            }
            if (!Schema::hasColumn('shareholder_api_infos', 'api_key')) {
                $table->string('api_key', 100)->nullable()->comment('api_key, 與外站API交互MD5加密之密鑰')->after('api_url');
            }
            if (!Schema::hasColumn('shareholder_api_infos', 'whitelist')) {
                $table->text('whitelist')->nullable()->comment('白名單')->after('api_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shareholder_api_infos', function (Blueprint $table) {
            $columns = ['type', 'source', 'currency_id', 'api_key', 'whitelist'];
            $existing = array_filter($columns, fn ($col) => Schema::hasColumn('shareholder_api_infos', $col));
            if ($existing) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
