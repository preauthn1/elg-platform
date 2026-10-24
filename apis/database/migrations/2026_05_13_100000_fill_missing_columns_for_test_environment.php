<?php

declare(strict_types=1);

use App\Facades\SchemaMonitor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 補齊本機與測試環境 DB 相對正式環境所缺少的欄位。
     * 若曾以舊檔名 `*_add_profit_share_columns_to_shareholder_categories_and_vendors` 執行過，
     * 請將 `migrations` 表對應列改為本檔名，避免 migrate 狀態不一致。
     */
    public function up(): void
    {
        SchemaMonitor::table('shareholder_categories', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('shareholder_categories', 'bet_ratio')) {
                $table->integer('bet_ratio')->default(100)->after('code')->comment('公司投注額分紅比例');
            }
            if (!SchemaMonitor::hasColumn('shareholder_categories', 'commission_ratio')) {
                $table->integer('commission_ratio')->default(0)->after('bet_ratio')->comment('公司業績分紅比例');
            }
            if (!SchemaMonitor::hasColumn('shareholder_categories', 'profit_share_visible')) {
                $table->boolean('profit_share_visible')->default(true)->after('commission_ratio')->comment('股東佔成管理 > 遊戲類別佔成設定 是否顯示');
            }
        });

        SchemaMonitor::table('vendors', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('vendors', 'show_on_profit_share')) {
                $table->boolean('show_on_profit_share')->default(true)->after('show_on_app')->comment('佔成會員是否顯示');
            }
            if (!SchemaMonitor::hasColumn('vendors', 'calc_on_profit_share')) {
                $table->boolean('calc_on_profit_share')->default(true)->after('show_on_profit_share')->comment('佔成會員是否計算至佔成(目前4D跟M9BET排除)');
            }
        });
        // 資料表: realname_validation_requests
        // 僅目標有的欄位:
        //   + username
        SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('realname_validation_requests', 'username')) {
                $table->string('username', 100)->default('')->index()->comment('會員帳號');
            }
        });

        // 資料表: event_details
        // 僅來源有的欄位:
        //   - tgid_block
        SchemaMonitor::table('event_details', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('event_details', 'tgid_block')) {
                $table->integer('tgid_block')->default(0)->after('realname_block')->comment('檢查telegram user id');
            }
        });

        // 資料表: event_invite_records
        // 僅來源有的欄位:
        //   - prize_member_id
        SchemaMonitor::table('event_invite_records', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('event_invite_records', 'prize_member_id')) {
                $table->integer('prize_member_id')->nullable()->after('block_member_id')->comment('領獎後綁定prize_members.id');
            }
        });

        //  資料表: order_histories
        //  僅來源有的欄位:
        //   - tax
        SchemaMonitor::table('order_histories', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('order_histories', 'tax')) {
                $table->decimal('tax', 18, 4)->default(0)->after('rate')->comment('手續費');
            }
        });

        // 資料表: summary_shareholder_temps
        // 僅來源有的欄位:
        //   - bet_ratio
        //   - commission_ratio
        //   - net_win
        //   - original_net_win
        SchemaMonitor::table('summary_shareholder_temps', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('summary_shareholder_temps', 'bet_ratio')) {
                $table->integer('bet_ratio')->default(100)->after('share_point')->comment('投注額比例');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholder_temps', 'commission_ratio')) {
                $table->integer('commission_ratio')->default(0)->after('bet_ratio')->comment('佔成比例');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholder_temps', 'net_win')) {
                $table->decimal('net_win', 18, 4)->default(0)->after('original_nett_profit')->comment('正收益總業績');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholder_temps', 'original_net_win')) {
                $table->decimal('original_net_win', 18, 4)->default(0)->after('net_win')->comment('原正收益總業績');
            }
        });
        // 資料表: summary_shareholder_winlose_temps
        // 僅來源有的欄位:
        //     - win
        SchemaMonitor::table('summary_shareholder_winlose_temps', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('summary_shareholder_winlose_temps', 'win')) {
                $table->decimal('win', 18, 4)->default(0)->after('winlose')->comment('贏分');
            }
        });

        // 資料表: summary_shareholders
        // 僅來源有的欄位:
        //     - direct_win
        //     - direct_win_nett_profit
        //     - direct_win_rate
        //     - team_win
        //     - team_win_nett_profit
        //     - team_win_rate
        SchemaMonitor::table('summary_shareholders', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('summary_shareholders', 'direct_win')) {
                $table->decimal('direct_win', 18, 4)->default(0)->after('team_nett_profit')->comment('直屬正收益業績');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholders', 'direct_win_rate')) {
                $table->decimal('direct_win_rate', 5, 4)->default(0)->after('direct_win')->comment('直屬正收益業績比例');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholders', 'direct_win_nett_profit')) {
                $table->decimal('direct_win_nett_profit', 18, 4)->default(0)->after('direct_win_rate')->comment('直屬正收益業績分紅');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholders', 'team_win')) {
                $table->decimal('team_win', 18, 4)->default(0)->after('direct_win_nett_profit')->comment('團隊正收益業績');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholders', 'team_win_rate')) {
                $table->decimal('team_win_rate', 5, 4)->default(0)->after('team_win')->comment('團隊正收益業績比例');
            }
            if (!SchemaMonitor::hasColumn('summary_shareholders', 'team_win_nett_profit')) {
                $table->decimal('team_win_nett_profit', 18, 4)->default(0)->after('team_win_rate')->comment('團隊正收益業績分紅');
            }
        });

        // 資料表: vip_contents
        // 僅來源有的欄位:
        //     - exclusive_service
        SchemaMonitor::table('vip_contents', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('vip_contents', 'exclusive_service')) {
                $table->tinyInteger('exclusive_service')->default(0)->after('is_downgrade')->comment('專屬服務經理');
            }
        });

        // 資料表: oppo_accounts
        // 僅來源有的欄位:
        //   - status
        SchemaMonitor::table('oppo_accounts', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('oppo_accounts', 'status')) {
                $table->tinyInteger('status')->default(1)->after('member_id')->comment('狀態 0:關閉,1:正常');
            }
        });

        // 資料表: pussy888_accounts
        // 僅來源有的欄位:
        //   - password
        SchemaMonitor::table('pussy888_accounts', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('pussy888_accounts', 'password')) {
                $table->string('password', 191)->default('Aa123466')->after('player_id')->comment('玩家密碼');
            }
        });
        // 資料表: agent_cash_flow_order_types
        //   僅來源有的欄位:
        //     - tax
        //     - tax_percentage
        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax')) {
                $table->decimal('tax', 18, 4)->default(0)->after('max_amount')->comment('手續費');
            }
            if (!SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax_percentage')) {
                $table->decimal('tax_percentage', 8, 4)->default(0)->after('tax')->comment('手續費(百分比)');
            }
        });

        // 資料表: bank_transaction_logs
        // 僅來源有的欄位:
        //     - agent_id
        SchemaMonitor::table('bank_transaction_logs', function (Blueprint $table) {
            if (!SchemaMonitor::hasColumn('bank_transaction_logs', 'agent_id')) {
                $table->integer('agent_id')->default(0)->after('source')->comment('代理系統編號');
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SchemaMonitor::table('vip_contents', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('vip_contents', 'exclusive_service')) {
                $table->dropColumn('exclusive_service');
            }
        });

        SchemaMonitor::table('summary_shareholders', function (Blueprint $table) {
            $columns = [
                'team_win_nett_profit',
                'team_win_rate',
                'team_win',
                'direct_win_nett_profit',
                'direct_win_rate',
                'direct_win',
            ];
            $toDrop = array_values(array_filter(
                $columns,
                fn (string $column): bool => SchemaMonitor::hasColumn('summary_shareholders', $column)
            ));
            if ($toDrop !== []) {
                $table->dropColumn($toDrop);
            }
        });

        SchemaMonitor::table('summary_shareholder_winlose_temps', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('summary_shareholder_winlose_temps', 'win')) {
                $table->dropColumn('win');
            }
        });

        SchemaMonitor::table('summary_shareholder_temps', function (Blueprint $table) {
            $columns = [
                'original_net_win',
                'net_win',
                'commission_ratio',
                'bet_ratio',
            ];
            $toDrop = array_values(array_filter(
                $columns,
                fn (string $column): bool => SchemaMonitor::hasColumn('summary_shareholder_temps', $column)
            ));
            if ($toDrop !== []) {
                $table->dropColumn($toDrop);
            }
        });

        SchemaMonitor::table('order_histories', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('order_histories', 'tax')) {
                $table->dropColumn('tax');
            }
        });

        SchemaMonitor::table('event_invite_records', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('event_invite_records', 'prize_member_id')) {
                $table->dropColumn('prize_member_id');
            }
        });

        SchemaMonitor::table('event_details', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('event_details', 'tgid_block')) {
                $table->dropColumn('tgid_block');
            }
        });

        SchemaMonitor::table('realname_validation_requests', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('realname_validation_requests', 'username')) {
                $table->dropColumn('username');
            }
        });

        SchemaMonitor::table('vendors', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('vendors', 'calc_on_profit_share')) {
                $table->dropColumn('calc_on_profit_share');
            }
            if (SchemaMonitor::hasColumn('vendors', 'show_on_profit_share')) {
                $table->dropColumn('show_on_profit_share');
            }
        });

        SchemaMonitor::table('shareholder_categories', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('shareholder_categories', 'profit_share_visible')) {
                $table->dropColumn('profit_share_visible');
            }
            if (SchemaMonitor::hasColumn('shareholder_categories', 'commission_ratio')) {
                $table->dropColumn('commission_ratio');
            }
            if (SchemaMonitor::hasColumn('shareholder_categories', 'bet_ratio')) {
                $table->dropColumn('bet_ratio');
            }
        });

        SchemaMonitor::table('agent_cash_flow_order_types', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax_percentage')) {
                $table->dropColumn('tax_percentage');
            }
            if (SchemaMonitor::hasColumn('agent_cash_flow_order_types', 'tax')) {
                $table->dropColumn('tax');
            }
        });

        SchemaMonitor::table('pussy888_accounts', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('pussy888_accounts', 'password')) {
                $table->dropColumn('password');
            }
        });

        SchemaMonitor::table('oppo_accounts', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('oppo_accounts', 'status')) {
                $table->dropColumn('status');
            }
        });

        SchemaMonitor::table('bank_transaction_logs', function (Blueprint $table) {
            if (SchemaMonitor::hasColumn('bank_transaction_logs', 'agent_id')) {
                $table->dropColumn('agent_id');
            }
        });
    }
};
