---
type: Rule
id: rules/currency-and-wallet-currency
title: 幣別與錢包幣別
description: 平台認得哪些標準幣別、哪些能當錢包幣別、會員錢包何時建立並記下錢包幣別，以及商戶幣別回填、稽核命令與 TrustPay 缺幣別時的擋單。
domain: 金流
subdomain: 渠道與貨幣
tags: [金流, 貨幣管理, 幣別, 幣種, currency, 標準幣別, 錢包幣別, 通道幣別, 商戶幣別, 單位倍率, 顯示單位, 幣別主檔, 多幣別錢包, multi-currency wallet, 會員錢包, 遊戲錢包, 活動錢包, 禮碼錢包, 泥碼錢包, 影子同步, 雙寫, USDT, TON, BUSD, KVND, KIDR, 虛擬貨幣, crypto, 組織幣別, TrustPay, 會員]
entities:
  - command: currency:backfill-vendor-info
  - command: currency:audit-vendor-info
  - command: currency:audit-orphan-parent
  - command: wallet:verify-balance
  - class: 'App\Services\WalletService'
  - class: 'App\Enums\CurrencyEnum'
  - class: 'App\Enums\WalletEnum'
  - class: 'App\Models\Member'
  - class: 'App\Services\Currency\CurrencyCodeResolver'
  - class: 'Database\Seeders\Concerns\GuardsCurrencyMasterData'
  - class: 'App\Jobs\Wallet\SyncMemberBalanceToWalletJob'
  - class: 'App\Services\Payment\Providers\TrustPay\TrustPayTrait'
  - class: 'App\Services\Payment\Providers\TrustPay\Withdrawal'
  - api: POST frontend/api/wallet/game-logs
  - api: POST frontend/api/wallet/histories
  - api: POST frontend/api/wallet/main-logs
  - api: POST frontend/api/wallet/transfer
  - api: POST frontend/api/member/check-transfer-status
  - api: POST frontend/api/member/transfer
  - api: POST frontend/api/member/transfer-all
  - api: POST frontend/api/member/transfer-back-recent
  - api: POST frontend/api/member/vendor-balance
  - table: currencies
  - table: members
evidence:
  - kind: code
    ref: /app/Services/WalletService.php
    title: 會員錢包的單一建立入口、錢包幣別的決定、已廢除類型只查不建、多錢包設定的中止守衛
    at: 2026-10-20
  - kind: code
    ref: /app/Enums/CurrencyEnum.php
    title: 幣別編號與可當錢包幣別的判定
  - kind: code
    ref: /app/Enums/WalletEnum.php
    title: 錢包類型三分法（帶幣別、非貨幣、已廢除）
  - kind: code
    ref: /app/Models/Member.php
    title: 會員幣別取自所屬代理的組織幣別，取不到時為 0
  - kind: code
    ref: /database/data/currencies.php
    title: 幣別主檔資料檔（六站共用的編號與代碼）
  - kind: code
    ref: /database/data/currency_aliases.php
    title: 幣別代碼對照契約（寫法修正、千元寫法、虛擬貨幣、非幣別、未查明）
  - kind: code
    ref: /app/Services/Currency/CurrencyCodeResolver.php
    title: 商戶幣別字串對到幣別主檔編號的解析鏈與判定
  - kind: code
    ref: /database/seeders/Concerns/GuardsCurrencyMasterData.php
    title: 幣別主檔同步前的編號與代碼一致性守衛
  - kind: code
    ref: /database/migrations/2026_09_07_000001_add_unique_index_to_currencies_code.php
    title: 幣別代碼唯一鍵
  - kind: code
    ref: /app/Console/Commands/Currency/BackfillVendorInfoCurrencyCommand.php
    title: 遊戲商戶與活動商戶的幣別編號回填（currency:backfill-vendor-info）
  - kind: code
    ref: /app/Console/Commands/Currency/AuditVendorInfoCurrencyCommand.php
    title: 遊戲商戶幣別代碼稽核（currency:audit-vendor-info）
  - kind: code
    ref: /app/Console/Commands/Currency/AuditOrphanParentCommand.php
    title: 會員幣別解析鏈稽核（currency:audit-orphan-parent）
  - kind: code
    ref: /app/Http/Controllers/Frontend/WalletController.php
    title: VWorld 一般錢包與遊戲錢包互轉，查無遊戲錢包時拒絕
  - kind: code
    ref: /app/Http/Controllers/Frontend/MemberTransferController.php
    title: 餘額回應中的遊戲錢包餘額在無遊戲錢包時回 0
  - kind: code
    ref: /app/Jobs/Wallet/SyncMemberBalanceToWalletJob.php
    title: 會員餘額影子同步到一般錢包（雙寫開關控制，預設關閉）
  - kind: code
    ref: /app/Console/Commands/Schedule/VerifyMemberWalletBalance.php
    title: 錢包影子餘額每日校驗（wallet:verify-balance）
  - kind: code
    ref: /app/Services/Payment/Providers/TrustPay/TrustPayTrait.php
    title: TrustPay 幣別代碼轉換與缺幣別擋單
  - kind: code
    ref: /app/Services/Payment/Providers/TrustPay/Withdrawal.php
    title: TrustPay 代付取取款銀行的通道幣別
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2109
    title: 多幣別錢包第零期注單契約凍結與第一期錢包入口收斂、影子同步
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2156
    title: 多幣別錢包第二期幣別主檔治理（主檔補齊、商戶幣別欄位、回填與稽核命令）
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2248
    title: USDT 回復為獨立幣別，虛擬貨幣放行為錢包幣別
  - kind: mr
    ref: http://example.invalid:1688/target/gffv3/apis/-/merge_requests/2259
    title: 幣別主檔清洗引用面盤點；TrustPay 幣別 id 缺失時擋單
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4245
    title: "[GFF]多幣別錢包 Phase 0：契約凍結與寫入者歸屬"
  - kind: ticket
    ref: http://example.invalid:1688/target/tickets/-/work_items/4246
    title: "[GFF]多幣別錢包 第一期：寫入點收斂與錢包表結構"
  - kind: ticket
    ref: http://example.invalid:1688/target/ticket_tasks/-/work_items/1267
    title: 多幣別錢包第二期幣別主檔治理
  - kind: code
    ref: /docs/plans/2026-08-13-multi-currency-wallet/multi-currency-wallet-decisions.md
    title: 多幣別錢包決議清單（決議⑬、㉚、㉛）
related:
  - rules/payout-submit-and-status
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

平台用到的幣別分成幾種，各自記在不同地方：

- 幣別主檔是平台認得的標準幣別清單，六個站點用同一份編號。
- 會員的錢放在錢包裡。每個錢包有一個錢包幣別，在錢包建立的當下決定，之後不再改。
- 每個遊戲商戶各記一個商戶幣別。
- 支付通道與取款銀行上記的是通道幣別。金流商建單時，要把通道幣別換成幣別代碼送出去。

多幣別錢包案正在分期進行。目前會員的一般錢包餘額仍以會員主檔為準。錢包表只先記下幣別，並建好影子同步與每日校驗；影子同步是把會員主檔的餘額另外寫一份到錢包表，開關預設關閉。

本份目前收錄的範圍：

- 幣別主檔有哪些幣別，以及怎麼保持一致。
- 哪些幣別可以當錢包幣別，以及 USDT 的定位。
- 會員錢包何時建立，以及錢包幣別怎麼決定。
- 已廢除的遊戲錢包停止新建。
- 影子同步與每日校驗。
- 商戶幣別的回填與稽核命令。
- TrustPay 的通道幣別缺失時擋單。

顯示單位、可用幣別與預設幣別的設定、會員切換幣別等條目，隨後續 MR 補入。

## 怎麼觸發

代理後台 › 系統管理 › 貨幣管理：新增與編輯幣別主檔。這個畫面在舊代理後台，本 repo 只負責在資料庫上限制幣別代碼不能重複；畫面上的驗證訊息待確認。

命令列（在容器內依站點指定連線執行）：

- `php artisan currency:backfill-vendor-info`：回填遊戲商戶與活動商戶的幣別編號。
- `php artisan currency:audit-vendor-info`：稽核遊戲商戶的幣別代碼能否對到標準幣別。
- `php artisan currency:audit-orphan-parent`：稽核會員的幣別解析鏈斷在哪裡。
- `php artisan wallet:verify-balance`：錢包影子餘額校驗，排程每日 04:10 自動執行。

會員 App：錢包沒有獨立畫面，會在會員查詢餘額、領活動獎勵、在遊戲商下注等操作時建立。VWorld 的一般錢包與遊戲錢包互轉在會員 App › 錢包（畫面名稱待確認）。

TrustPay 代收在會員 App › 充值頁送出時觸發；代付在代理後台設定代付時觸發，流程見 [rules/payout-submit-and-status](payout-submit-and-status.md)。

## 規則

### 幣別主檔

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 幣別主檔目前有 24 種，六個站點的編號一致：MYR 1、SGD 2、VND 3、VND2 4、KVND 5、IDR 6、IDR2 7、KIDR 8、THB 9、USDT 10、FON 11、GCASH 12、BUSD 13、MMK 14、PayMaya 15、HKD 16、PGK 17、AUD 18、BDT 19、TON 20、INR 21、PHP 22、USD 23、CNY 24。PHP、USD、CNY 是 2026-09 補進的，接續在既有編號之後。 | code:currencies.php |
| R-2 | 同一個幣別代碼在幣別主檔只能有一筆，只差大小寫（例如 `php` 與 `PHP`）也算同一個代碼，第二筆寫不進去。 | code:GuardsCurrencyMasterData、code:2026_09_07_000001_add_unique_index_to_currencies_code |
| R-3 | 同步幣別主檔時，只要有一筆的編號已被別的代碼佔用，或代碼已存在於別的編號，整次同步就拒絕寫入，並列出每一筆衝突（例如「id 22 已被 ZZT 佔用，資料檔要放的是 PHP」）。系統不會略過衝突的那筆、把其他筆照寫。主檔已與資料檔一致時，重跑不改任何資料。 | code:GuardsCurrencyMasterData |
| R-4 | USDT 是獨立的標準幣別（編號 10），不對應美元。以 USDT 記帳的遊戲商戶，商戶幣別解析為 USDT，不是 USD。 | code:CurrencyCodeResolver |

### 哪些幣別可以當錢包幣別

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-5 | 千元寫法只是同一種錢的計價單位：以 KVND、KIDR 記帳的遊戲商戶，商戶幣別記為 VND、IDR，千元與元之間的差距由該遊戲商戶設定上的單位倍率吸收，不另成一個幣別。 | code:CurrencyCodeResolver、code:currency_aliases.php |

### 會員錢包的建立與錢包幣別

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-6 | 會員的活動錢包、禮碼錢包等，在第一次用到時才建立（例如會員查詢餘額、領活動獎勵、在遊戲商下注時）。同一會員同一類錢包只會有一個，同時有多個請求要建時也只建出一個。 | code:WalletService |
| R-7 | 帶幣別的錢包（一般、活動、禮碼）建立時記下錢包幣別。觸發的操作有指定幣別時用指定的，沒指定時用會員所屬代理的組織幣別。會員的上層代理不存在、代理的組織不存在，或組織沒設幣別時，錢包幣別記為 0（未定）。 | code:WalletService、code:Member |
| R-8 | 錢包建立後，錢包幣別不再改變。之後組織幣別換了，既有錢包照舊沿用，幣別不被改寫，也不會因此多建一個新錢包。 | code:WalletService |
| R-9 | 不是錢的錢包（積分、排行榜點數）錢包幣別固定為 0，即使觸發的操作指定了幣別也一樣。 | code:WalletService、code:WalletEnum |
| R-10 | 已廢除的遊戲錢包與商城幣錢包不再新建。已經存在的照樣讀得到、餘額照樣能轉出，只是不會再長出新的。 | code:WalletService、code:WalletEnum |
| R-11 | VWorld 會員在一般錢包與遊戲錢包之間互轉時，若沒有既有的遊戲錢包，回應「Game wallet not found」（HTTP 400）。這時一般錢包餘額不動，也不會替會員建一個遊戲錢包。有既有遊戲錢包的會員照常互轉。 | code:WalletController |
| R-12 | 會員沒有遊戲錢包時，查詢餘額、轉帳結果等回應裡的遊戲錢包餘額顯示 0。 | code:MemberTransferController |
| R-13 | 代理的多錢包設定若被開啟，該代理底下會員取用錢包的操作直接中止並回錯誤，不會把遊戲錢包當成一般錢包繼續扣款或入帳。這個設定已隨遊戲錢包廢除，不得再開。 | code:WalletService |

### 影子同步與每日校驗

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-14 | 會員的一般錢包餘額仍以會員主檔為準。雙寫開關預設關閉，關閉時會員餘額變動不會同步到錢包表。開啟後，每次餘額變動都把當下的餘額覆寫到該會員的一般錢包（沒有就先建，錢包幣別依 R-7）。同步失敗不影響原本的入帳或扣款。 | code:SyncMemberBalanceToWalletJob |
| R-15 | 每日 04:10，系統比對會員主檔餘額與錢包表的一般錢包餘額，比到小數第 10 位。有差異時發告警，列出差異筆數，以及最多 5 筆的會員編號與兩邊金額，不改任何資料。還沒有一般錢包的會員不列入比對，不算差異。 | code:VerifyMemberWalletBalance |

### 商戶幣別回填與稽核命令

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-16 | 回填命令預設只預覽、不寫入，加 `--execute` 才寫。它只處理遊戲商戶與活動商戶中還沒有幣別編號的，已有編號的不改。可用 `--table` 限定其中一張表、`--vendor-id` 限定一家遊戲商；`--table` 填其他值會被拒絕。寫入時保留商戶原本的最後異動時間，每一筆寫入都留紀錄。 | code:BackfillVendorInfoCurrencyCommand |
| R-17 | 回填時依序做三件事：先修正商戶幣別字串的寫法差異（例如 HKB 視為 HKD、kIDR 視為 KIDR），再把千元寫法對回標準幣別（R-5），最後要在幣別主檔找到同一個代碼與編號才寫。對不到的一律跳過不寫，不猜最像的，也不填主幣別。主檔還沒有那一列的幣別也跳過。 | code:BackfillVendorInfoCurrencyCommand、code:CurrencyCodeResolver |
| R-18 | 回填命令與商戶幣別稽核命令的結束狀態：有任何啟用中的商戶對不到標準幣別時，命令以失敗結束；對不到的只有停用中的商戶時，只列出、不算失敗。虛擬貨幣與非幣別代碼另外標示類別，但對得到編號，不算對不到。 | code:AuditVendorInfoCurrencyCommand |
| R-19 | 會員幣別稽核命令只讀不寫，逐一判定每個會員的幣別斷在哪一步：上層代理未指派、上層代理不存在、代理的組織不存在、組織沒設幣別、組織的幣別不在主檔，或順利解析。只要有一個會員解析不出幣別，命令就以失敗結束。對上層代理不存在或未指派的會員，命令另外列出可推回歸屬的線索（子代理的組織、會員代理對照、代理搬移紀錄）。子代理分散在不同組織時，不推定組織。 | code:AuditOrphanParentCommand |

### TrustPay 的幣別代碼

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-20 | TrustPay 代收與代付送出的幣別代碼取自通道幣別：代收用支付通道上的通道幣別，代付用取款銀行上的通道幣別。送出的是幣別代碼本身（例如 MYR），並且算進請求簽名。 | code:TrustPayTrait、code:Withdrawal |
| R-21 | TrustPay 代收時，若支付通道的通道幣別沒設、為 0，或不是平台認得的幣別編號，系統不送請求給 TrustPay。會員收到失敗回應「TrustPay invalid currency id: <值>」（HTTP 500），系統留下錯誤紀錄，供排查是哪個金流商戶。這張充值訂單沒有送到金流商，不會入帳。 | code:TrustPayTrait |
| R-22 | TrustPay 代付時，若取款銀行的通道幣別沒設，或不是平台認得的幣別編號，系統不送請求、不建代付請求紀錄，視為明確拒絕。取款訂單轉「三方失敗」（29），錯誤提示「TrustPay invalid currency id: <值>」。補好設定或換金流商戶後，可以重新設定代付（見 [rules/payout-submit-and-status#R-3](payout-submit-and-status.md)）。 | code:Withdrawal |

## Examples

會員所屬代理的組織幣別是 MYR：

| 情境 | 結果 | 依據 |
|---|---|---|
| 會員第一次查詢餘額，還沒有禮碼錢包 | 建立禮碼錢包，錢包幣別 MYR | R-6、R-7 |
| 之後組織幣別改成 SGD，會員再查詢 | 沿用原本的禮碼錢包，錢包幣別仍是 MYR，不另建 | R-8 |
| 會員的上層代理已被刪除，第一次建活動錢包 | 錢包幣別記為 0 | R-7 |
| 建排行榜錢包時指定 SGD | 錢包幣別仍為 0 | R-9 |
| VWorld 會員沒有遊戲錢包，從一般錢包轉 100 到遊戲錢包 | 回「Game wallet not found」，一般錢包餘額不變 | R-11 |

遊戲商戶的幣別字串與回填結果（加 `--execute`）：

| 商戶幣別字串 | 回填的編號 | 依據 |
|---|---|---|
| MYR | 1（MYR） | R-16 |
| KVND | 3（VND），不是 5 | R-5、R-17 |
| HKB | 16（HKD） | R-17 |
| USDT | 10（USDT），不是 23 | R-4 |
| MSC，商戶啟用中 | 不寫，命令以失敗結束 | R-17、R-18 |
| MSC，商戶已停用 | 不寫，只列出 | R-18 |
