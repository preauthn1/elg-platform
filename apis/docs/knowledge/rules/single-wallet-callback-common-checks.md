---
type: Rule
id: rules/single-wallet-callback-common-checks
title: 單一錢包回調的共用檢查
description: 已改用共用前置檢查的無縫錢包回調要先通過哪些檢查，以及各家遊戲商在這些檢查擋下時回什麼錯誤碼。
domain: 遊戲與遊戲商
subdomain: 遊戲商 callback
tags: [遊戲商, 無縫錢包, 單一錢包, 回調, callback, single wallet, 會員]
entities:
  - class: 'App\Http\Controllers\External\SingleWallets\BaseController'
evidence:
  - kind: code
    ref: /app/Http/Controllers/External/SingleWallets/BaseController.php
    title: 無縫錢包回調共用的前置檢查
    at: 2026-10-20
  - kind: outline
    ref: http://0.0.0.0:3000/doc/05-99-callback-nMhA2aPoBG
    title: Outline 原頁（遷入後刪除）
related:
  - rules/vendor-motivationsw
  - rules/vendor-spadegamingsw
status: draft
generated: { by: claude-code/claude-opus-5-5, at: "2026-10-21T10:42:47+08:00" }
---

## 這個主題在講什麼

遊戲商的回調是遊戲商主動呼叫我方 API 的行為，包括投注、派彩、取消、查餘額等無縫錢包回調，以及轉帳錢包的回調。

本份放無縫錢包回調共用的前置檢查，以及各家遊戲商的錯誤碼對照。各家遊戲商回調的實際行為各自成一份文件，見 frontmatter 的 `related`。

本份目前收錄的範圍：只適用已改用共用前置檢查的遊戲商，目前是 MotivationSW 與 SpadegamingSW。其餘各家的前置檢查在自己的回調裡實作，擋下的情況與錯誤碼可能不同（例如停用時連查餘額、派彩都擋），寫在各家自己的文件。

## 怎麼觸發

沒有操作畫面。玩家在遊戲內操作時，遊戲商會呼叫我方為該遊戲商設的無縫錢包回調入口。QA 可以在 dev 站進入對應的遊戲下注來觸發。

## 規則

這些檢查由已改用共用前置檢查的遊戲商共用，那幾家的文件不重複寫。各家遊戲商回的錯誤碼見下面的對照表。

| 編號 | 敘述 | 證據 |
|---|---|---|
| R-1 | 在正式環境，來源 IP 不在該遊戲商戶的白名單時，任何回調都被擋下，回該遊戲商的錯誤碼，不動錢包、不寫注單。白名單為空時不檢查。 | code:BaseController |
| R-2 | 回調的簽章或 token 與我方用遊戲商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 | code:BaseController |
| R-3 | 以下三種情況擋下回調，並回該遊戲商的錯誤碼：回調帶的玩家帳號在我方不存在、帳號所屬的遊戲商戶不存在、帳號屬於別家遊戲商的遊戲商戶。系統不會拿別家遊戲商戶的設定算錢。 | code:BaseController |
| R-4 | 玩家帳號存在但尚未綁定會員時，回調預設被擋下，並回該遊戲商的錯誤碼。遊戲商宣告允許池帳號（尚未綁定會員的玩家帳號）的回調放行，例如 MotivationSW 的查玩家、查餘額。 | code:BaseController |
| R-5 | 玩家帳號停用或遊戲商戶停用時，查餘額照回餘額，不回錯誤。停用只在下注時擋。 | code:BaseController |
| R-6 | 下注時遇到以下任一情況就擋下，回該遊戲商的錯誤碼，不建注單、不扣款：玩家帳號停用、遊戲商戶停用、會員不具下注資格（例如會員停用、鎖分模式：會員參與活動期間，錢包之間的餘額轉移受到限制的狀態）。 | code:BaseController |
| R-7 | 回調帶的金額一律是商戶幣別（遊戲商戶記帳用的幣別）。我方依遊戲商戶的匯率換算成平台金額後才動錢包，回給遊戲商的餘額一律乘回匯率。 | code:BaseController |

### 各家的錯誤碼

| 情況 | MotivationSW | SpadegamingSW |
|---|---|---|
| IP 不在白名單（R-1） | 1100 | 2 |
| 簽章或 token 不符（R-2） | 1100 | 2，訊息「Digest verify failed」 |
| 帳號不存在（R-3） | 1006 | 50100 |
| 遊戲商戶不存在或屬於別家（R-3） | 1006 | 10113 |
| 尚未綁定會員（R-4） | 查玩家回存在、查餘額回 0；其他回調 1006 | 50101 |
| 不具下注資格（R-6） | 1100 | 50103，訊息「Account could not bet yet」 |
