<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DriftSubjectEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 漂移比對基準：某個監測對象「上次看到的內容」。
 *
 * 一列 ＝ 某個監測對象（`subject_key`）在某個範圍（`scope_key`）下的一份已知內容。
 * 用途是抓「有東西被改掉而沒人發現」——三方在不通知的情況下改設定、我方設定被手動動過，
 * 都只有比對前後兩份才看得出來。首個監測對象是 FPay 代付銀行清單
 * （`cash-flow:audit-fpay-bank-list`）。
 *
 * ## 接入契約（新增監測對象前先讀完）
 *
 * 1. **`subject_key` 一律取自 {@see DriftSubjectEnum}**，新增對象＝新增一個 case。
 *    不得寫入 class 名稱（FQCN）：class 改名搬家會讓歷史列一夕失聯。
 *    ⚠️ 約束只在寫入端——**讀取端刻意不做 enum cast**，因為共用表沒辦法保證每一條
 *    讀取路徑都只讀到自己的列（理由見下方 `casts()`）。
 * 2. **`scope_key` 的語意由各監測對象自訂**，但必須是 canonical 字串、無前後空白、
 *    長度不超過 50。FPay 銀行清單用代付設定 id（該三方的代號按商戶帳號各發一套，
 *    不同代付設定的內容天生不同、不可互比）。
 * 3. **查詢一律用字串綁定**。`scope_key` 是 varchar，傳整數進去會讓該欄位退化成數值比較、
 *    索引失效。
 * 4. 🔴 **只記「當時看到的內容」，禁止加 status 之類的欄位記「這次抓失敗了」。**
 *    整套判斷建立在「**查無列＝真的還沒有基準**」這個不變式上；一旦有人用失敗列填進來，
 *    第一次執行與抓取失敗就分不出來，會把失敗當成新基準、從此不再告警。抓取失敗要走
 *    各自的告警路徑，不要寫進這張表。
 * 5. **內容沒變就不要新增列**。每天塞一份一模一樣的內容只會讓表長大，也會讓例行執行
 *    從唯讀變成寫入。列數應該反映「改過幾次」而不是「跑過幾次」。
 * 6. **表共用後沒有全域清理**。寫入量大的監測對象要自帶清理排程；
 *    另注意 `SchemaMonitor` 對超過 50 萬列的表會擋 ALTER。
 *    ⚠️ 加清理排程等於替這張表開了刪除路徑。現在沒有任何監測對象會刪列，所以也還沒有
 *    「不得誤刪別的監測對象的列」那條測試；第一個加清理的人要連那條一起補。
 *
 * 只有 `created_at`、沒有 `updated_at`——基準建立後不修改，要換基準就新增一列。
 *
 * @knowledge rules/payout-bank-code-mapping
 *
 * @property int $id
 * @property string $subject_key 監測對象（DriftSubjectEnum 的值）
 * @property string $scope_key 範圍鍵（語意由各監測對象自訂）
 * @property string|null $scope_label 範圍的人可讀標籤，只供閱讀、不參與比對
 * @property int|null $item_count 內容筆數；內容不是清單時為 NULL
 * @property array<array-key, mixed> $payload 已知內容本身
 * @property Carbon|null $created_at
 *
 * @method static Builder<static>|DriftBaseline newModelQuery()
 * @method static Builder<static>|DriftBaseline newQuery()
 * @method static Builder<static>|DriftBaseline query()
 *
 * @mixin \Eloquent
 */
class DriftBaseline extends Model
{
    /** 表沒有 updated_at 欄位；不關掉的話 Eloquent 會寫入不存在的欄位 */
    public const UPDATED_AT = null;

    protected $guarded = [
        'created_at',
    ];

    /**
     * `subject_key` 刻意**不做 enum cast**。
     *
     * 這是共用表，列裡會有別的監測對象的值。一旦 cast 成 Enum，只要有任何一條讀取路徑
     * 撈到還沒登記進 {@see DriftSubjectEnum} 的值，就會在**讀取階段**拋 `ValueError`，
     * 而且是整支程式炸掉、不是略過一列。
     *
     * 只帶自己 `subject_key` 條件的查詢確實讀不到別人的列。問題在於**共用表上沒辦法保證
     * 每一條讀取路徑都永遠只讀自己的列**——不帶區分鍵的列表或報表、保留期清理排程、
     * 某個 Enum case 被移除後留下的舊列，都會踩到，而且都是日後才加上去的東西。
     *
     * Enum 的角色是「寫入端的合法值登記處」（見 {@see self::scopeForScope()} 的參數型別），
     * 不是讀取端的型別守衛。讀取端真的需要 Enum 型別時，在自己的程式碼裡用 `tryFrom()`
     * 並處理 `null`。
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * 某個監測對象在某個範圍下的全部基準，最新的在前。
     *
     * `scope_key` 強制轉字串再綁定：欄位是 varchar，傳整數會讓比較退化成數值、索引失效。
     */
    public function scopeForScope(Builder $query, DriftSubjectEnum $subject, string|int $scopeKey): Builder
    {
        return $query->where('subject_key', $subject->value)
            ->where('scope_key', (string) $scopeKey)
            ->orderByDesc('id');
    }
}
