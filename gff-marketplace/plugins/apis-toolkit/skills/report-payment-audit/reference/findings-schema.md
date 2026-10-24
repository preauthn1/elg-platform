# 風險發現資料檔的格式與寫入規則

資料檔是 APIs repo 的 `docs/reports/data/payment-risk-findings.json`，它是風險發現的唯一資料來源。報告由它產生，修復進度也記在它裡面。這份文件說明它的結構、每個欄位怎麼填、寫入時要守的規則。

## 結構

```jsonc
{
  "generatedAt": "YYYY-MM-DD",          // 最近一次全面重審的日期
  "updatedAt": "YYYY-MM-DD",            // 最近一次任何寫入的日期
  "source": "skill:report-payment-audit",
  "note": "...",                        // 稽核範圍、基準 commit、限制，寫給讀報告的人
  "categories": [ { "key": "...", "label": "..." } ],
  "statuses":   [ { "key": "...", "label": "..." } ],
  "shared": {                           // 共用層（不屬於單一金流商）的發現，不計入已審家數
    "auditedAt": "YYYY-MM-DD",
    "findings": [ /* 同下方發現格式 */ ]
  },
  "providers": {
    "<金流商目錄名>": {
      "auditedAt": "YYYY-MM-DD",
      "status": "clean | findings",     // 沒有任何發現時是 clean，findings 留空陣列
      "findings": [ /* 發現 */ ]
    }
  }
}
```

## 一筆發現的欄位

新增發現時，每個欄位都要寫出來，不可省略。範例（值為示意）：

```json
{
    "id": "PRF-198",
    "severity": "real",
    "category": "payout-sync-misreject",
    "status": "open",
    "title": "代付同步回應只要不是成功碼就判拒絕（觸發條件未驗證）",
    "file": "app/Services/Payment/Providers/ExamplePay/Withdrawal.php",
    "line": 64,
    "detail": "現象、成因、影響範圍、證據。",
    "fix": "建議修法。",
    "askVendor": true,
    "rootCause": null,
    "tickets": [],
    "decision": ""
}
```

型別：`line` 是整數；`askVendor` 是布林值（`true` 或 `false`，不寫成文字）；`rootCause` 是字串或 `null`；`tickets` 是字串陣列；其餘都是字串。新增時 `status` 一律 `open`、`tickets` 是空陣列、`decision` 是空字串、沒有共用成因的 `rootCause` 是 `null`。

| 欄位 | 填什麼 | 誰寫 |
|---|---|---|
| `id` | 固定編號 `PRF-NNN`。新發現取資料檔目前最大號加一。重審時同一個問題沿用原編號，不重新編號 | 審查模式新增時 |
| `severity` | `real`（真實 bug）、`drift`（文檔失準，或程式能動但寫法有問題，例如死碼、寫死的測試值）、`known`（已記載的限制或慣例偏差）。判準見 SKILL.md「發現分級」 | 審查模式 |
| `category` | 問題分類的 `key`，清單見下節 | 審查模式 |
| `status` | `open`（待處理）、`ticketed`（已開單）、`wontfix`（拍板不修）、`fixed`（已修）。誰可以改、怎麼改見「處理狀態怎麼變」 | 見下方 |
| `title` | 一句話說清楚問題。程式路徑確定、但會不會發生取決於三方行為的，標題結尾加「（觸發條件未驗證）」 | 審查模式 |
| `file`、`line` | 問題所在的完整相對路徑與行號。重審時重新查行號，不沿用舊值 | 審查模式 |
| `detail` | 現象、成因、影響範圍、證據 | 審查模式；查證紀錄與修正依據的寫法見「寫入規則」 |
| `fix` | 建議修法 | 審查模式 |
| `askVendor` | 修之前是否要先向金流商取得資訊（狀態碼表、欄位語意、簽名算法等） | 審查模式 |
| `rootCause` | 同一個成因在共用層的那筆發現的 `id`；沒有就是 `null` | 審查模式 |
| `tickets` | 工單陣列，例如 `["target/tickets#4446"]` | 更新狀態模式 |
| `decision` | 拍板不修或改判的理由、拍板人與日期，以及上線順序這類限制 | 更新狀態模式 |

## 問題分類

分類與 APIs repo 的 `docs/金流接入流程/附錄-常見缺陷類型.md` 一一對應，原理、正確做法與檢查方式都在附錄。資料檔 `categories` 的 `label` 與附錄的節標題逐字相同，報告靠它指向附錄。新增或改名分類時，資料檔、附錄與下表要在同一個 MR 一起改。

| key | 名稱 |
|---|---|
| `payout-sync-misreject` | 一、代付送出後，把「不是成功」一律當成「明確拒絕」 |
| `payout-empty-error` | 二、代付被拒絕，但錯誤訊息是空字串，被當成已受理 |
| `payout-callback-binary` | 三、代付回調把「不是成功」一律標成失敗 |
| `deposit-callback-binary` | 四、代收回調把「不是成功」一律標成失敗 |
| `callback-authenticity` | 五、回調的真實性沒有防線 |
| `callback-amount` | 六、回調金額的比對有缺口 |
| `sensitive-exposure` | 七、敏感資訊外洩 |
| `payout-callback-lookup` | 八、代付回調找單與狀態守衛 |
| `payout-precheck` | 九、放款前反查端點 |
| `outbound-request` | 十、送給三方的請求內容有誤 |
| `error-handling` | 十一、錯誤處理與告警 |
| `doc-drift` | 十二、文件與資料檔失準 |

文檔失準與已知限制，對得上前十一類的照分，其餘歸第十二類。

## 處理狀態怎麼變

| 變化 | 什麼情況 | 誰做 |
|---|---|---|
| 新增 → `open` | 新發現一律從待處理開始 | 審查模式 |
| `open` → `ticketed` | 開了工單 | 更新狀態模式 |
| 任何狀態 → `wontfix` | 使用者拍板不修，`decision` 寫理由 | 更新狀態模式 |
| `wontfix` → `open` 或 `ticketed` | 使用者改判要修，`decision` 補改判理由，保留原本不修的理由 | 更新狀態模式 |
| `open` 或 `ticketed` → `fixed` | 確認修正已在 master | 更新狀態模式，或重審時核對已修 |
| `fixed` → `open` | 重審發現問題又出現 | 重審 |

`fixed` 或 `wontfix` 的發現補登工單時，只加 `tickets`，狀態不變，除非使用者明確要求改判。

## 寫入規則

- **重審一家時不整家覆蓋**：以既有節點為底逐筆更新。稽核內容（`severity`、`category`、`title`、`file`、`line`、`detail`、`fix`、`askVendor`、`rootCause`）可以更新；`tickets`、`decision` 一律保留；`status` 只照上一節「處理狀態怎麼變」裡標「重審」的兩種情況改，其餘保留。
- **查證紀錄與修正依據用追加的方式寫**：寫在 `detail` 結尾，各自一段，開頭固定為「查證（YYYY-MM-DD）：」或「修正依據：」。重審改寫 `detail` 時，這些段落原樣保留，只改前面的稽核描述。
- **核對已修**：原本 `open` 或 `ticketed` 的發現在現行程式裡找不到了，先確認是真的修掉（看修正的 commit 或現行程式邏輯），再標 `fixed`，並追加一段「修正依據：」寫 commit SHA 或 MR。只是行號移動不算已修。
- **已修的不刪**：`fixed` 的發現保留在資料檔，報告預設隱藏。之後問題又出現，改回 `open` 並追加一段說明。
- **共用層的發現放 `shared`**，各家同一成因的發現用 `rootCause` 指過去，不在各家重複寫成因。
- **只有主對話寫資料檔**：並行的 agent 只回報發現，由主對話統一寫入，避免互相覆蓋。
- **寫入後驗證**：用腳本讀回，確認 `id` 沒有重複；每筆的欄位齊全、型別正確；`category` 與 `status` 都在清單內；`rootCause` 指向的 `id` 存在；重審前後 `tickets`、`decision` 沒有被改掉。
- **不落機密與個資**：只寫變數名稱與用途，不寫憑證值；引用真實訂單或會員資料前先遮蔽（手機 `0912***456`、帳號只留末 4 碼），訂單號與 `member_id` 可以原樣。
