---
description: 審查代碼品質並可選擇套用修正；無任務描述為純 review，有任務描述則先列計畫再 refactor。
argument-hint: "<目標檔案或目錄> [任務描述]"
disable-model-invocation: true
---

目標與任務描述：$ARGUMENTS

---

> **每次只處理一件事。** 若發現其他問題，列於「待處理」區塊，本次略過。

## 模式判斷

- **純 review**：`$ARGUMENTS` 只給目標，未提供任務描述 → 走 Step 1 後停手，不動檔
- **Bug 修復**：任務描述是錯誤、例外、不正確行為 → Step 1 → 等使用者確認 → Step 2 → Step 3
- **重構**：任務描述是代碼品質、架構、語法改善 → Step 1 → 等使用者確認 → Step 2 → Step 3，不得改變現有行為

## Step 1：診斷與分級

讀檔掃描，按下列嚴重度分級輸出問題與計畫異動範圍（規則細節依專案既有規範與 `laravel-best-practices` skill；本命令只定義「分級」與「輸出格式」）。掃描時對目標中新增的方法 / 邏輯，另以相似命名與關鍵字 grep 既有代碼，發現疑似重複既有實作者列入問題清單：

### 🔴 Critical（P0，必須修正）

- 金流正確性（浮點運算 / 外部 float 轉型）、安全（未綁定 `DB::raw` / config 外 `env()`）、資料完整性（寫入未包交易 / 讀取未 `lockForUpdate` / 回調缺冪等）、缺 `declare(strict_types=1)`、Controller 含業務邏輯、金流商憑證 / 密鑰 / 回調 URL 寫死在代碼（no-hardcode 規則；應走 config / DB 配置）、`DB::table()` 繞過既有 Model（改用 Eloquent；唯一例外是複雜度高且跨多表的查詢，須附行內註解）

### 🟡 Warning（P1，建議修正）

- N+1、魔術數字未封 Enum、參數 >3 未具名、`ApiException` 非英文、`Log::error` 非繁中、trace 未用 `Common::formatTrace($e)`、Trait 屬性非 `protected`、白標或環境相關值寫死（no-hardcode 規則；白標名、URL、IP、agent_id、金額門檻，應走 config / 白標配置）、新增邏輯與既有 Service / Helper 明顯重複（應復用或抽共用，不複製貼上改值）

### 🟢 Suggestion（P2，可選優化）

- `switch`→`match`、冗長 null 判斷→`?->`、普通 class→`readonly`、手動賦值→Constructor Property Promotion、常數→Enum 語意化

### Step 1 輸出格式

```
模式：純 review / Bug 修復 / 重構
主軸／根因：（一句話）

問題清單：
[Critical] 檔案名:行號 — 問題描述
現有代碼：...
建議改為：...

[Warning] 檔案名:行號 — 問題描述
...

計畫異動範圍：
- 檔案名:行號 — 說明

待處理（本次略過）：
- 問題描述 — 檔案名:行號
```

純 review 模式到此停手。其他模式停在此處等待使用者回應「繼續 / 不要 / 調整範圍」。

## Step 2：套用修正

確認後依優先順序套用。

**共用約束**：
- 不得改變現有行為，不得刪除業務邏輯
- 保留所有現有註解，必要時同步更新與邏輯不符的部分
- 不順手重構範圍外的代碼

## Step 3：執行驗證

**有對應測試** — 跑該測試：

```bash
docker exec php-fpm8 bash -c "cd /var/www/APIs && php artisan test --filter=TargetClassTest"
```

**無對應測試**（例：未納管的 artisan command、新檔尚未補測試）— 改跑可行驗證手段，最低標：

```bash
# 1. PHP 語法檢查
docker exec php-fpm8 bash -c "cd /var/www/APIs && php -l <目標檔案>"

# 2. 視類型補對應 smoke test：
#    - Artisan command → php artisan <signature> 跑一次無破壞性參數
#    - Service / Helper → php artisan tinker --execute 'App\Services\...'
#    - Route handler   → curl 對應 endpoint 或開瀏覽器確認
```

驗證綠燈後回報完成；失敗則回到 Step 2 修正。
