<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Patch;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 把同一金流商「舊版」的整套渠道配置，遷移到該金流商「新版」之下。
 *
 * 【分類】Patch — 🔄 **保留，待重構**（RB 2026-07-28 定案）。
 *
 * ## 真實用途（2026-07-28 由 RB 澄清，**推翻先前的退役判定**）
 * 主要目的是**把同一個金流商舊版的配置（`agent_cash_flows`、`order_types`、
 * `order_type_names`、`agent_cash_flow_order_types`）遷移到該金流商新版本之下**——
 * 亦即 provider 版本升級時的配置搬遷，不是通用的「渠道間複製」工具。這也解釋了它為何
 * 無條件覆寫 `cost`／`rate`：遷移語意本就要求整套沿用舊值。
 *
 * **之後會重構為更好用的版本；在那之前保留，勿刪。**
 * （2026-07-27 初版判定「零引用＋sorts 是唯一缺口 → 退役候選」**失準**：它誤把本命令當成
 * 建新渠道的工具來比對，忽略了版本遷移這個真實情境。）
 *
 * ## ⚠️ 重構前若非跑不可
 * 它**無條件覆寫 `agent_cash_flows.cost` 與 `rate`**，且**無 `--dry-run`、無 confirm**。
 * 執行前請自行備份目標渠道的三層配置。
 *
 * ## 與 config-driven 流程的分工（2026-07-27 盤點，此部分仍有效）
 * 下列項目在「**新建**渠道」情境已由 config-driven 流程接手，該情境不需要本命令：
 *
 * | 項目 | 新建渠道時由誰負責 |
 * |---|---|
 * | 多語系名稱 `order_type_names` | `SyncCashFlowsCommand::syncOrderTypeNames()`（來源 `Data/providers/*.php`）|
 * | `agent_cash_flows.cost` | `AddAgentCashFlow` 由 config channel 定義帶入 |
 * | `agent_cash_flows.rate` | `SyncCashFlowsCommand::normalizeForEnvironment`（依環境主幣）|
 * | `order_types.image_path` | config／後台 |
 * | `agent_cash_flow_order_types.sorts` | `AddAgentCashFlow` 建 ACFOT 時固定 `'sorts' => 0`，之後由後台調整 |
 *
 * **建新渠道走 `agent:add-agent-cash-flow` ＋ `cash-flow:sync`**（配置寫進
 * `Data/providers/{Name}.php`）；**本命令是給「舊版 → 新版遷移」用的**，兩者情境不同。
 *
 * 註：舊系統 `RD-Studio-01/_release/app/Console/Commands/CashTool/SyncOrderType.php` 有同源版本。
 *
 * ---
 *
 * 以下為原始行為說明，供退役前萬一需要理解它做了什麼時參考。
 *
 * ## 發生原因
 * 新建一條支付渠道（cash_flow + agent_cash_flow + order_type 三層）後，展示層與費率
 * 要逐項手動設定：圖示、費率、匯率、排序、每個語系的名稱。項目多且散在三張表，
 * 人工設定既慢又容易漏——尤其多語系名稱要一個語言一筆。實務上新渠道多半與既有渠道
 * 配置雷同，因此以「複製既有渠道」取代「從零設定」。
 *
 * ## 修正目的
 * 依 `source` → `target` 複製下列項目（**單一 transaction**，任一步失敗全部回滾）：
 *
 * | 項目 | 來源 → 目標 | 條件 |
 * |---|---|---|
 * | `order_types.image_path` | source → target | source 非空且兩者不同才複製 |
 * | `agent_cash_flows.cost` | source → target | **無條件覆寫** |
 * | `agent_cash_flows.rate` | source → target | **無條件覆寫** |
 * | `agent_cash_flow_order_types.sorts` | source → target | **無條件覆寫** |
 * | `order_type_names`（多語系名稱） | source → target | target 缺該語系則新增、名稱不同則更新 |
 *
 * 複製 `order_type_names` 時 `cash_flow_id` 會改寫為 **target 的** cash_flow_id
 * （不是照抄 source 的），以維持 denormalize 欄位的正確性。
 *
 * ## 觸發時機
 * 1. **新建支付渠道後**，要沿用既有渠道的費率與展示配置
 * 2. 同一金流商新增幣別／通道，配置應與既有通道一致時
 *
 * ## 冪等性與重跑安全性
 * **冪等（結果收斂），但寫入沒有任何前置保護。** 重跑會得到相同結果（target 被覆寫成
 * 與 source 相同），不會累積副作用。
 *
 * ⚠️ **執行前務必確認參數**——本命令與其他 Maintenance 命令不同：
 * - **無 `--dry-run`**：不能預覽，執行即寫入
 * - **無 `confirm()`**：不會二次確認
 * - **無環境確認**：不會檢查當前 DB_CONNECTION 打到哪個白標
 * - `cost` / `rate` 是**金流費率欄位**，覆寫錯 target 會直接影響該渠道的費用計算
 *
 * 參數格式為 `{cash_flow_id}/{agent_cash_flow_id}/{order_type_id}`，三個 ID 都要對，
 * 但命令**只驗證 order_type 與 agent_cash_flow 是否存在，不驗證三者是否互相隸屬**——
 * 傳錯組合不會被擋下。執行前建議先手動查一次 target 的現值，以便必要時還原。
 *
 * （退役條件見本檔開頭的「⛔ 退役判定」，此處不重複。）
 *
 * ## 關聯
 * - 命令名 `ctool_sync_order_type` 是**舊命名慣例殘留**（現行為 `cash-flow:` / `fix:` 前綴），
 *   改名須同步更新既有 runbook 與操作紀錄，尚未處理。
 * - 三層結構說明：`app/Console/Commands/CashFlow/AddAgentCashFlow.php`
 * - denormalize 欄位（cash_flow_id）：`cash-flow:backfill-cash-flow-id`
 *
 * 用法：
 *   php artisan ctool_sync_order_type {source} {target}
 *   php artisan ctool_sync_order_type 3/71/67 97/284/516
 *                                     └ source        └ target
 *                                     cash_flow_id / agent_cash_flow_id / order_type_id
 */
class SyncOrderType extends Command
{
    protected $signature = 'ctool_sync_order_type {source} {target}';

    protected $description = '複製支付渠道配置（圖示／費率 cost／匯率 rate／排序／多語系名稱）到另一條渠道';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        DB::transaction(function () {
            $source = $this->argument('source');
            [$s_cash_flow_id, $s_agent_cash_flow_id, $s_order_type_id] = explode('/', $source);
            $target = $this->argument('target');
            [$t_cash_flow_id, $t_agent_cash_flow_id, $t_order_type_id] = explode('/', $target);

            $s_order_type = OrderType::query()->find($s_order_type_id);
            $t_order_type = OrderType::query()->find($t_order_type_id);

            if (!$s_order_type || !$t_order_type) {
                $this->error('source or target order type not found');

                return;
            }
            $s_agent_cash_flow = AgentCashFlow::query()->find($s_agent_cash_flow_id);
            $t_agent_cash_flow = AgentCashFlow::query()->find($t_agent_cash_flow_id);

            if (!$s_agent_cash_flow || !$t_agent_cash_flow) {
                $this->error('source or target agent cash flow not found');

                return;
            }

            if (!empty($s_order_type->image_path) && $s_order_type->image_path != $t_order_type->image_path) {
                $t_order_type->image_path = $s_order_type->image_path;
                $t_order_type->save();
                $this->line('同步 order_type image_path: ' . $s_order_type->image_path . ' -> ' . $t_order_type->image_path);
            }

            $original_cost = $t_agent_cash_flow->cost;
            $original_rate = $t_agent_cash_flow->rate;
            $t_agent_cash_flow->cost = $s_agent_cash_flow->cost;
            $t_agent_cash_flow->rate = $s_agent_cash_flow->rate;
            $t_agent_cash_flow->save();
            $this->line(sprintf('同步 agent_cash_flow cost: %s -> %s rate: %s -> %s', $original_cost, $t_agent_cash_flow->cost, $original_rate, $t_agent_cash_flow->rate));

            $s_agent_cash_flow_order_type = AgentCashFlowOrderType::query()->where('agent_cash_flow_id', $s_agent_cash_flow_id)->where('order_type_id', $s_order_type_id)->first();
            $t_agent_cash_flow_order_type = AgentCashFlowOrderType::query()->where('agent_cash_flow_id', $t_agent_cash_flow_id)->where('order_type_id', $t_order_type_id)->first();

            if (!$s_agent_cash_flow_order_type || !$t_agent_cash_flow_order_type) {
                $this->error('source or target agent cash flow order type not found');

                return;
            }

            $original_sorts = $t_agent_cash_flow_order_type->sorts;
            $t_agent_cash_flow_order_type->sorts = $s_agent_cash_flow_order_type->sorts;
            $t_agent_cash_flow_order_type->save();
            $this->line('同步 agent_cash_flow order type sorts: ' . $original_sorts . ' -> ' . $t_agent_cash_flow_order_type->sorts);

            $s_order_type_names = OrderTypeName::query()->where('type_id', $s_order_type_id)->get();
            $t_order_type_names = OrderTypeName::query()->where('type_id', $t_order_type_id)->get();

            foreach ($s_order_type_names as $row) {
                /** @var OrderTypeName $row */
                $exist = $t_order_type_names
                    ->where('language_id', $row->language_id)
                    ->where('type_id', $t_order_type_id)
                    ->first();
                if (!$exist) {
                    $insert = $row->toArray();
                    unset($insert['id'], $insert['created_at'], $insert['updated_at']);
                    $insert['type_id'] = $t_order_type_id;
                    // $row->toArray() 帶的是 source order_type_name 的 cash_flow_id；
                    // 這裡是複製到 target order_type，必須改用 target 的 cash_flow_id。
                    $insert['cash_flow_id'] = $t_order_type->cash_flow_id;
                    OrderTypeName::create($insert);
                    $this->line('新增: ' . $row->name);
                } elseif ($exist->name != $row->name) {
                    $exist->name = $row->name;
                    $exist->save();
                    $this->line('更新: ' . $row->name);
                }
            }

            $this->line('同步完成');
        });
    }
}
