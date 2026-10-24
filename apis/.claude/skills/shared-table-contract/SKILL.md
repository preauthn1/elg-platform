---
name: shared-table-contract
description: 把功能專屬的資料表改成多消費者共用的契約表時的設計檢查。當使用者說「這個表希望所有 X 都能用」「做成通用表」「共用這張表」「其他 job / 模組也要用這張表」「加個 type 欄位區分」，或 review 意見要求把專屬表通用化時載入。
---

# 共用資料表契約設計檢查

> 適用：把「某功能專屬的表」改成「多個消費者依 `job_key` / `type` / `source` 之類的區分鍵共用的表」。
> 不適用：新建就給多方使用的表（沒有「原本靠專屬性保證的性質」要交接）、純加欄位、純改索引。

## 為什麼要有這份檢查

通用化的成本不在改欄位，在於**原本由「表是專屬的」這件事免費保證的性質，全部變成要靠契約與測試守住**——而改造當下只有一個消費者，所有測試照樣全綠、行為完全正確，看不出保護已經拆掉。等第二個消費者接進來才一起引爆，且失敗方向清一色是**靜默**（互判完成、互刪紀錄、誤判缺漏），不是拋錯。

參考實作是 APIs 的 `queue_job_runs`（SBR 整點彙總完成標記）。

## 八條檢查（動手前逐條過，改完逐條驗）

### 1. 區分鍵用穩定邏輯鍵，不入庫 class 名（FQCN）

class 會改名搬家——同一支 MR 內就可能發生——FQCN 入庫會讓歷史列一夕失聯。用 Enum 管值（`QueueJobKeyEnum::SUMMARY_BET_RECORD = 'summary_bet_record'`），Enum docblock 註明值長度上限對齊欄位定義。

### 2. 字串鍵欄位一律 `utf8mb4_bin`

判斷邏輯若是「SQL 撈列 → PHP 端比對字串」兩段式，預設 `utf8mb4_unicode_ci` 會讓兩段對同一列給出**相反答案**：

- `PAD SPACE`：`'3' = '3 '` 為真（尾隨空白視為相等）
- 大小寫不敏感：`'aB' = 'Ab'` 為真

於是 `'3 '` 這樣的列，SQL 的 `whereIn('key', ['3'])` 撈得到、PHP 端字串比對卻不相等 → 判為「缺」→ 補寫時 `updateOrCreate` 的 where 又命中該列只做 UPDATE → **永久誤判、每輪重跑、不會自癒**。原本 int 欄位天然免疫（MySQL 寫入即正規化成 `3`），改 varchar 才引進此風險。

```php
$table->string('work_key', 50)->collation('utf8mb4_bin')->comment('...；必須為 canonical 字串、無前後空白');
```

**表還空的時候改是一行；長大後是唯一鍵上的 ALTER 加重複鍵清理，差一個量級。**

### 3. 禁止用字串串接組比對鍵

鍵語意開放給各消費者後，`$a . '|' . $b` 會被含分隔字元的值撞鍵，而撞出來的結果是「誤判為已完成」＝靜默漏做。改巢狀陣列：

```php
$done[$row->work_key][$row->bucket_time->format('Y-m-d H:i:s')] = true;
// 判斷：isset($done[(string) $workKey][$bucket])
```

### 4. 會落庫的值全部進 Enum

即使某個值「只有 ops 手動寫入、程式不產生」（例：部署預塞的 `preseed`），也要設 case。否則未來任何人補上 enum cast 或用 `from()` 做統計，讀到那些列就是 `ValueError`。用註解表達約束即可：

```php
/** 部署預塞：僅由上線預塞程序寫入，程式端不得產生（勿用於 markCompleted） */
case PRESEED = 'preseed';
```

### 5. 補隔離測試，而且要「拔掉隔離條件就會紅」

**最容易漏的一條。** 改造完成後原有測試全部只用單一消費者的區分鍵，把 helper 裡的 `where('job_key', $jobKey->value)` 整行刪掉仍會全綠——通用化唯一新增的語意零覆蓋。

自驗方式：註解掉那個 where，跑測試，**沒有紅就是沒測到**。至少三個斷言，用另一個區分鍵寫入同樣的 (key, bucket) 列：

- 存在性查詢不得看見別人的列
- 判缺／差集查詢仍判本消費者缺
- 重置／刪除不得誤刪別人的列

第二個區分鍵不必為測試新增 Enum case，直接以 `create()` 塞字串列即可（寫入路徑不受 Enum 限制）。

### 6. 既有測試斷言補上區分維度

`where('vendor_id', ...)`、全表 `count()` 在專屬表上語意精確；搬到共用表後，精確性從「設計保證」退化成「碰巧只有一個使用者」，第二個消費者接入後這些斷言會變成不穩定測試。加一個 helper 收攏，把「本測試只看自己的列」寫成程式碼而非默契：

```php
private function sbrRuns(): Builder
{
    return QueueJobRun::query()->where('job_key', QueueJobKeyEnum::SUMMARY_BET_RECORD->value);
}
```

### 7. 接入契約寫進 Model docblock，逐條列

新消費者接入前要能一次讀完約束。至少涵蓋：

- **寫入時機**：標記／紀錄與業務資料是否必須同一個 DB 交易提交（rollback 時一併消失，「缺列＝缺資料」才成立）
- **只記成功、禁加 status 欄**：一旦有人加欄位記失敗，「缺列＝缺資料」失效，整套判缺垮掉——這條要明文寫，因為 `..._runs` 之類的表名會誘導後人往「執行紀錄表」的方向加欄位
- **鍵格式**：canonical 字串、無前後空白、長度上限
- **查詢綁定**：一律字串綁定（int 綁定會讓 varchar 欄位退化為數值比較、索引失效）
- **保留視窗**：表共用後無全域清理，量大的消費者要自帶清理排程（另注意 `SchemaMonitor` 對超過 50 萬列的表會擋 ALTER）
- **冪等**：重跑同一工作單位不得重複計帳

### 8. 索引照實際查詢形狀重排

唯一鍵欄序若吃不到主要查詢形狀（例：只帶時間範圍、不帶 work_key），補第二索引成 covering，把 `IN` 條件的欄位放最後：

```php
$table->unique(['job_key', 'work_key', 'bucket_time'], 'uk_job_work_bucket');
$table->index(['job_key', 'bucket_time', 'work_key'], 'idx_job_bucket_work');
```

驗證用 `EXPLAIN FORMAT=JSON` 看 `used_key_parts`，不要憑推論。

## 收尾

1. 全量 grep 舊表名／舊 Model 名／舊 Enum 名，確認零殘留（含 `docs/`、測試、baseline 檔）。
2. 已跑過舊 migration 的環境（dev、共用測試庫）會留下**孤兒表＋孤兒 migrations 列**——migration 改名後 `migrate:rollback` 遇到缺檔只印 `Migration not found` 就跳過，不刪表也不清紀錄。清理順序固定：**先刪 `migrations` 紀錄 → 再 drop 舊表 → 最後補資料**（反過來會失去回滾自癒能力）。舊資料有保留價值時用 `INSERT ... SELECT` 轉寫進新表，不要盲塞。
3. 改動涉及金流／併發或跨系統雙軌時派圓桌複審（`timo` 併發與正確性、`marz` 查詢效率與舊系統對照、`rhyse` craft 與測試強度、`rorin` 部署與回滾路徑）。第 2 條（定序）與第 5 條（隔離測試）這類問題，單一審查角度容易漏看。
