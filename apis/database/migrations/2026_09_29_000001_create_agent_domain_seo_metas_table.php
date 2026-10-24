<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO 元數據 target/tickets#4102
 *
 * 前台各頁面輸出的 <title>／<meta description>／<meta keywords>，每個頁面（見 SeoPageEnum）一列。
 *
 * 綁在「域名」身上（agent_domains.id）：同一總代可能掛多個品牌域名，各自需要不同的 SEO 文案。
 * 同一總代線（agents.role_id=11）最多一個域名標為預設（is_default=1），該線其他沒設定的域名
 * 回退用它；general_agent_id 冗餘存一份，讓前台回退查詢不必每次反查代理階層。
 * 單一文字不分語系。
 */
return new class extends Migration
{
    public function up(): void
    {
        SchemaMonitor::create('agent_domain_seo_metas', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('流水號');
            $table->unsignedInteger('agent_domain_id')->comment('agent_domains.id');
            $table->unsignedInteger('general_agent_id')->comment('該域名所屬總代 agents.id（role_id=11），供同線預設回退查詢');
            $table->string('page', 30)->comment('頁面代碼，見 SeoPageEnum');
            $table->string('title', 255)->default('')->comment('頁面標題');
            $table->string('description', 500)->default('')->comment('頁面描述');
            $table->string('keywords', 500)->default('')->comment('逗號分隔');
            $table->tinyInteger('is_default')->default(0)->comment('1=此域名為該總代線的預設回退（同線同 page 只能一筆）');
            $table->timestamp('created_at')->useCurrent()->comment('建立日期');
            $table->timestamp('updated_at')->useCurrentOnUpdate()->useCurrent()->comment('更新日期');
            $table->integer('admin')->default(0)->comment('更新者ID');

            // 每個域名每個頁面只有一份設定
            $table->unique(['agent_domain_id', 'page'], 'uq_agent_domain_seo_metas_domain_page');
            // 前台回退：依總代取預設域名的設定
            $table->index(['general_agent_id', 'is_default', 'page'], 'idx_agent_domain_seo_metas_general_default_page');
        });

        // SchemaMonitor 靜默跳過防護：斷言建表生效，否則拋錯不讓 migration 被記為完成
        if (!Schema::hasColumn('agent_domain_seo_metas', 'is_default')) {
            throw new RuntimeException('agent_domain_seo_metas 建表未生效（SchemaMonitor 可能靜默跳過），中止');
        }
    }

    public function down(): void
    {
        SchemaMonitor::dropIfExists('agent_domain_seo_metas');
    }
};
