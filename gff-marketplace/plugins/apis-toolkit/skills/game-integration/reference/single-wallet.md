# 單一錢包（Single Wallet）

> 這份管單一錢包（共用錢包，遊戲商用回調扣款與派彩）的 Driver、帳號建立、回調 Controller，以及所有動錢回調的鎖、冪等、取消退款、錯誤碼、來源驗證、金額換算與 TBR 建立時機。選型確定是單一錢包、要寫 Driver 或任何回調時讀；主檔紅線速查表 R1～R26 的條文都在這份。

## 必須實作的方法

```php
class Vendor extends AbstractSingleWallet
{
    /** 取得遊戲啟動網址 */
    public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData { ... }
}
```

## 建立遊戲帳號（createAccount）

`create_vendor_account` 排程呼叫 `Driver::createAccount()` 預建帳號池。**實作前必須確認 `api.md` 是否有「建立玩家帳號」端點**：

| 情況 | 實作方式 |
|------|---------|
| **有**建立帳號 API | **兩段式 `member_id`**：插入即以 `-999999999` 佔位，三方確認建成才翻 `0` 入池 |
| **無**建立帳號 API（純 seamless wallet） | 只寫本地記錄即可，玩家身份於回調時驗證（驗證方式見下方紅線） |

> 🔴 **必須是兩段式，不可「先入池、失敗才標記」**。`member_id` 預設 `0` 就是「未綁定、可被領走」的狀態——先入池等於在三方還沒建成的空窗期就開放認領，存在「**三方尚未建成即被玩家綁走**」的競態（`785ad3d8a`）。哨兵值佔位讓失敗路徑什麼都不必做，也就沒有「回頭標記來不及」的窗口。

> 🔴 **新 vendor 必須顯式 implement `ShouldScheduleAccountCreation`**，不可依賴 id 範圍之類的隱式涵蓋。舊排程以「vendor id ≥ 176」自動涵蓋，改由介面決定範圍後，沒宣告的 vendor 會掉出自動補池、連手動補建都被判未實作（`785ad3d8a`）。完整排程語意（降級為 `SupportsAccountCreation` 退出排程、聚合商標記後旗下全部納入、APIS／RD-Studio 兩端禁止同時補池）以 `app/Vendors/Contracts/ShouldScheduleAccountCreation.php` 的 docblock 為權威出處；驗收條件見 [`reference/acceptance-contract.md`](acceptance-contract.md) B-6。

> 🔴 **補建流程禁止把三方呼叫包進 `DB::transaction`**。逐帳號打三方開戶原本整商包在一個交易裡，job 逾時被砍就整趟回滾、當輪已建好的帳號一列不留，而三方那邊帳號其實已經開好——VWorld NextSpin(48) 因此連續 26 天補不進任何帳號，池子耗盡後新玩家拿不到帳號（`4c3023802`，`target/op_tickets#3463`）。現行寫法三件事成套：
> - 單筆 INSERT 自身即原子，**不包交易**；中間的 `member_id = -999999999` 就是「已送給三方」的憑據，中斷時留著才有東西對帳
> - 補建帶**時間預算**（job `$timeout` 的八成），到點收手，剩餘缺口下一輪自 DB 重算續補
> - 水位計數與流水號查詢**走主庫**：每輪只補一部分，讀到 replica 舊值會重複補建、甚至拿已用掉的號重送三方

> 🚫 **會被三方重送的動錢回調（settle／cancel／refund），不可用 session token 反查帳號**。`Driver::getGameUrl()` 每次進遊戲都會重發 token，token 輪換是常態不是特例：三方重送帶的是**下注當時**的舊 token，用「帳號現行 token == 請求 token」定位就**永遠找不到玩家**，已扣款的注單卡住不動（Astar 曾因此大量注單卡在 ROLLOUT，細節見其 `integration.md`）。
> 這類回調一律以**不可變識別**定位：`round → 注單 → 由注單的 vendor_username 反查帳號`；取消投注與退款（cancel／refund）的請求若有帶玩家 id 再比對，防跨玩家沖帳。
> 例外：派彩類回調（settle、settleChange）查注單只比 `bet_id`（三方單號），不另比請求帶的玩家 id；防偽造請求靠 IP 白名單與驗簽（見「來源驗證與憑證（R16～R21）」節），不靠業務查詢多一個條件（理由見 [deliberate-decisions.md](deliberate-decisions.md)「憑證與驗證」）。
> 例外一：**下注（bet／rollout）不在此限**——下注當下玩家必在活體 session，用現行 token 驗證是正確的授權語意（Astar 的 `rollout()` 即如此，見其 `integration.md`）。
> 例外二：`verifySession` 這類「驗證 token 本身」的端點本來就該查 token（`GMSWController::resolveBySessionToken()`，且該方法只被 `verifySession()` 使用）。

**有三方 API 的標準寫法**（參考 `app/Vendors/AiGamingSW/Driver.php`；AFBSW 仍是舊的「失敗才標記」寫法，勿照抄）：

```php
public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
{
    $this->setVendorInfo($params->vendorInfo);

    // 第一段：插入即以哨兵值佔位，此時不可被玩家領走
    $account = $this->accountModel::create([
        'vendor_info_id' => $params->vendorInfo->id,
        'username'       => $params->username,
        'member_id'      => -999999999,
    ]);

    if (!$account) {
        return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
    }

    // 呼叫三方 API 建立遊戲商帳號；失敗就讓它留在佔位狀態，不需回頭標記
    if (!$this->createVendorAccount($account)) {
        return CreateAccountResultData::from(['status' => false, 'username' => $params->username]);
    }

    // 第二段：三方確認建成，翻 0 入池
    $account->update(['member_id' => 0]);

    return CreateAccountResultData::from(['status' => true, 'username' => $params->username]);
}
```

**無三方 API 的簡化寫法**：

```php
public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
{
    $account = $this->accountModel::create([
        'vendor_info_id' => $params->vendorInfo->id,
        'username'       => $params->username,
    ]);

    return CreateAccountResultData::from([
        'status'   => (bool) $account,
        'username' => $params->username,
    ]);
}
```

## 回調 Controller 骨架

Single Wallet 的下注紀錄由遊戲商主動回調：

```
app/Http/Controllers/External/SingleWallets/{VendorName}Controller.php
routes/external/sw.php
```

Transfer Wallet 回調放於：
```
app/Http/Controllers/External/TransferWallets/{VendorName}Controller.php
routes/external/tw.php
```

**單錢包 Driver 要宣告預設回調路徑（`HasCallbackPath`）**。這步讓後台知道這家遊戲商的回調該打到哪裡：

- 單錢包 Driver 一律 `implements HasCallbackPath`，`defaultCallbackPath()` 回傳 `/external/api/sw/<prefix>`，`<prefix>` 與 `routes/external/sw.php` 為這家註冊的路由前綴逐字一致（例：`TTHSW` 的路由群組是 `sw/tthsw`，宣告 `/external/api/sw/tthsw`）。既有的 FFFSW 沒有回調路由，刻意不宣告。
- 路由寫成單條、沒有包群組的（例：`Route::post('rcb988', ...)`），一樣要宣告，回傳 `/external/api/sw/rcb988`。
- 轉帳錢包沒有回調路徑，不實作本介面。
- 用途：後台「遊戲商管理 › 廠商管理」用它組出完整的回調路徑（當站 apis 網域加上這段後綴）。`vendors.callback_path` 欄位可以在後台覆寫；清空（NULL）就退回 Driver 宣告的預設。
- 守門測試 `DriverCallbackPathDeclarationTest` 會擋三種錯：格式不符 `^(\/[A-Za-z0-9_-]+)+$`、還留著腳手架的 `TODO` 佔位值、宣告的路徑在已註冊路由中找不到（以前綴比對；不檢查是不是這家的路由，從範本複製 Driver 時要自己核對）。
- 介面定位與「接入通道」的宣告方式見 [artisan-and-docs.md](artisan-and-docs.md)「接入通道與回調路徑宣告」。

```php
class VendorNameController extends BaseController
{
    protected int $vendorId = VendorIdEnum::VENDOR_NAME->value;

    protected string $accountClass = XxxAccount::class;

    protected string $betRecordClass = XxxBetRecord::class;

    protected string $actionPrefix = 'Xxx_get_request_';

    /** vendor_infos.info 內的商戶代碼欄位名，供 getVendorInfo() 比對 */
    protected string $vendorInfoOperatorField = 'agentCode';

    public function callAction($method, $parameters)
    {
        if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
            return $this->errorResponse(/* 依 `api.md` 的「來源不合法」錯誤碼 */);
        }

        // 帳號／會員／商戶一律在交易外解析（R1）
        $this->setUpVendorAccount(thirdPartyUsername: (string) request()->input('playerId', ''));

        return parent::callAction($method, $parameters);
    }
}
```

> 🚫 **IP 白名單不要寫在建構子**：建構子跑在路由解析時、拿不到該次 action 的語境，也無法用回應碼回絕。現行 47 支單錢包 controller **沒有任何一支**在建構子驗，38 支放在 `callAction()` 開頭（其餘在各自的入口方法）。
>
> `$accountClass` / `$betRecordClass` / `$actionPrefix` / `$vendorInfoOperatorField` 四個欄位是 `BaseController::setUpVendorAccount()` 與 `getVendorInfo()` 的輸入，缺一那兩支就解析不出帳號。

## 交易與鎖（R1～R3）

這一節是整章的地基：R4 起的所有冪等守衛，都建立在「讀得到最新已提交的狀態」之上。

### R1｜交易內第一個 SQL 必須是鎖會員列

R4 的冪等閘門與 R13 的退款前驗證都建立在它之上。

- **位置**：每個會動錢包的 endpoint，`DB::transaction()` 的**第一行**。
- 🚫 進交易後、拿到會員鎖之前，**禁止任何一般讀**——包含看似無害的 lazy load（`$member->agent` 會查表）。RR 隔離級別下快照定在「本交易第一次一般讀」那一刻，之後即使等到鎖，一般讀看到的仍是等鎖前的舊照片。
- ✅ 一律以 `WalletService::getMemberLockedWallets()` 為交易內第一個 DB 動作（它第一句就是 `Member::lockForUpdate()`）。帳號、商戶、遊戲等解析**搬到交易外**（`callAction()`），可參考 `ClotPlayController`。
- ⭐ **滿足這個前提之後，交易內的一般讀就讀得到現值**（鎖定讀不建快照，快照改由拿到鎖之後的第一次一般讀建立）。這正是 R4 的閘門多數不必再自己加鎖的原因。
- 事故實證：`target/op_tickets#3075`（target 70 筆禮碼下注 cancel 走「查無回 OK」錢沒退）、`target/tickets#4294`。

### R2｜同一 controller 內所有動錢分支的鎖序必須一致

一律「**先鎖會員錢包列、再鎖定讀注單列**」。

- 🚫 把某個查詢改成鎖定讀時，必須回頭檢查它與其他分支有沒有形成鎖序倒轉。`settleChange()` 原本是「注單 → 會員」而 `settle()` 是「會員 → 注單」，前者改成鎖定讀之後即形成死鎖面，因此一併改為先鎖錢包（`8c86a78e1`）。
- 加鎖修競態時最容易順手製造的新缺陷就是這個；掉一次回調等於漏派彩或漏退款。

### R3｜修 stale snapshot 不能只修 controller

共用 Service 內部的 `updateOrCreate()`／`firstOrCreate()` **同樣是一般讀**，一樣看得到舊快照。

- 實證：只把注單層改成鎖定讀時，`TotalBetRecordService::create()` 仍走一般讀 → 看不到 placeBet 剛寫的 TBR → 改走 INSERT → 撞 `(vendor_id, bet_record_id)` 唯一鍵 → **整筆交易回滾回 500001，錢一樣沒退**（`8c86a78e1`）。
- ✅ 對「注單已存在」的情形改鎖定讀判斷；「注單不該存在」的新建路徑維持 `updateOrCreate`（gap lock 理由見 [deliberate-decisions.md](deliberate-decisions.md)「鎖與併發」）。

### 鎖 key 的規範

回調與補帳命令**必須共用同一支 `key()` 實作**，且：帶 vendor 前綴、`mb_strtolower()` 後 hash、總長不得超過 MySQL `GET_LOCK` 的 64 字元上限。✅ 藍本 `PlayTechSW\GameRoundLock::key()`（`228820ac4`，含三案回歸測試）。

⚠️ **named lock 不是唯一的序列化手段**：不走那把鎖的對帳／人工命令仍會直接改注單表，所以注單的 read-modify-write **仍須 `lockForUpdate()`**，不可因為「有 named lock」就省掉列鎖。

## 冪等與重送（R4～R10）

三方重送是常態不是例外。這一節決定「同一件事送第二次」會不會變成第二次動錢。

### R4｜撤單／退款的冪等鍵必須是「原下注」的識別

- **位置**：`cancel()`／`refund()` 進交易、鎖會員之後（見 R1）、`changeBalance()` **之前**。
- 🚫 **禁止**拿撤單請求自己的交易號當冪等鍵。三方沒收到回應就換一個新交易號重送，每次都被當成新請求 → 依「原 bet → 0」的差額**再退一次錢**，可無限重複。生產超退案例中，多數筆的 `created_at` 落在同一秒，只擋循序重送等於沒擋。
- **閘門要不要自己加 `lockForUpdate()`，看它查的列在不在會員鎖的涵蓋範圍內**：
  - 滿足 R1 時，同一會員的注單／deal record 已被會員鎖序列化，**一般讀即可**（`TTHSWController::findCancelForOriginalTransaction()`、PlayTech 皆如此）；讀「已被鎖住的那一列自己的欄位」更不需另外加鎖（`RgswController::isDuplicateCorrection()`）。
  - 只有查詢跨出會員鎖範圍時才加（`SpadegamingSWController::isBetAlreadyCanceled()`，其 docblock 記了代價：等於把全站 SG 撤單序列化）。
  - 🚫 **對「通常尚未存在」的唯一鍵絕不可加 `lockForUpdate()`**——會取 gap lock，三方單號單調遞增時併發 INSERT 互等成 1213 死鎖（`5e3cae00a` 為此移除六家的鎖；另見 [config-and-tools.md](config-and-tools.md)「遊戲帳號綁定並發規範」的同型規則）。
- ✅ 三種冪等鍵形態，依三方協定給什麼來挑；**三種都不適用時先與 PM 確認，不要自己發明第四種**：
  - 有唯一交易序號 → 比對序號：`TTHSWController::findCancelForOriginalTransaction()`（`OriginalTransactionId`）、PlayTech 的 `relatedTransactionCode`
  - 無序號但注單狀態能表達「已處理」→ 查狀態旗標：`SpadegamingSWController::isBetAlreadyCanceled()`（以原下注 `referenceId`）
  - 兩者皆無 → 內容指紋加時間窗：`RgswController::isDuplicateCorrection()`（見 R7）

### R5｜冪等比對的字串判定必須與 DB collation 同一把尺

- **位置**：所有冪等閘的字串比對處，以及 named lock 的 key builder。
- **判準是「上游用 SQL 找原交易」與「下游用 PHP 比對」必須用同一套大小寫規則**，不是無條件用某個函式：
  - 欄位是 `utf8mb4_unicode_ci`（不分大小寫，現有 SW 注單／deal record 多屬此類）→ PHP 端一律 `strcasecmp()`。用 `===` 的話，三方送 `abc123` 會在上游 SQL 命中原交易、在閘門比不中既有撤單列 → **放行再退一次**，改一個字母的大小寫即可繞過。
  - 欄位是 `utf8mb4_bin`（分大小寫）→ PHP 端用 `===`。此時改用 `strcasecmp` 是反向錯誤（SQL 分、PHP 不分 → 誤擋合法請求）。
- ⚠️ **接新 vendor 時先實查該欄位的實際 collation**，不要照抄別家的結論（TTHSW docblock 記的是 2026-09-08 實查六站的結果，只對該欄位成立）。
- ✅ named lock 的 key 先 `mb_strtolower()` 再 hash（`PlayTechSW\GameRoundLock::key()`），否則同一局會拿到兩把鎖、序列化被架空。

### R6｜我方標記是獨占命名空間，入庫前必須剝除三方同名鍵

- **位置**：把三方 payload 原文寫進**會被守衛讀回來判斷**的欄位的地方，例如 `PlayTechController::createDealRecord()`（deal record 的 `raw`）。
- 🚫 底線前綴（`_rejected`／`_recovery`）是**我方獨占**的標記。寫入前必須 `unset()` 三方 payload 裡的同名鍵，否則三方只要在 payload 多塞一個 `_rejected`，就能把「已生效的退款」偽造成「被拒絕」→ **整道重複退款閘 fail-open**。
- ⚠️ 失效方向必須是誤擋（可補、有 log），不可以是放行。
- 📌 `BaseController::appendParamsToInfo()` **刻意不做任何剝除**（RB 2026-09-07 裁決 `target/op_tickets#3076`）：注單 `info` 是對帳與爭議時的原始證據，要留原文。所以**判定用的欄位不要直接讀 `info` 的三方原文**，或讀之前自行排除底線鍵。

### R7｜冪等只比「決定動到哪一筆」的關鍵欄位，禁止比整包 payload

- **位置**：沒有三方交易序號、只能用內容指紋認的協定（疊加調整型：refund／credit／debit／sumBet）。
- 🚫 **禁止** `$request->all()` 全比。協定裡只要有任一會變動的欄位（RG 的 `payout_time` 生產上 443 筆全不重複），整包比對**永遠不相等 → 守衛靜默失效、重複入帳，而且不留任何訊號**。
- ✅ 必須有顯式的欄位白名單（`RGSW\Enums\CorrectionTypeEnum::identityKeys()`），逐端點宣告參與比對的欄位集，加金額，再加時間窗。
- ⚠️ 時間窗長度多半沒有三方文件依據、也無法用歷史校準（生產從未發生過重送時，歷史資料是空的）。**照抄別家的秒數前先確認它是怎麼來的**。

### R8｜用來比對的型別字串必須封成 Enum

- **位置**：型別字串的定義處。
- 🚫 字串一旦從「只寫進 DB」升級成「拿來比對」，就必須封 Enum。`'debit'` 打成 `'debet'` 即守衛永不命中，且不會有任何錯誤。
- ⚠️ 封 Enum 時**值要沿用既有字面**，以相容生產上已寫入的資料。

### R9｜閘門粒度必須對齊三方的退款粒度

- 協定允許同局多筆下注時，冪等鍵要用「**單筆下注**」而非「整局」。只做整局閘會誤擋第二筆合法退款（**少退**）。
- 反過來只做單筆閘也不夠：補帳命令補的是「整局退款」事件，在 multi-bet 局裡比不中其餘下注 → **會再退一次**（審查實測）。
- ✅ PTSW 因此做**兩層**：單筆用 `relatedTransactionCode`、整局用 `_recovery` 標記（`36b6f757d`）。
- 粒度的選擇與依據要寫在該閘門的 docblock 裡——太粗漏退、太細重退，兩個方向都出事。

### R10｜扣款類端點的重送守衛必須排在餘額檢查之前

- `debit`／`sumBet` 這類**會讓餘額變小**的端點，第一次扣款成功後餘額已經下降；重送時若先跑餘額檢查，會直接被判餘額不足而**根本問不到守衛**。
- 後果：三方收到的是「餘額不足」而不是「這筆我處理過了」，一筆已成功的扣帳被當成失敗，三方可能因此沖正（`97a4fba7f`）。
- ✅ 順序固定為：**判重送 → 餘額檢查 → 扣款**。

### 冪等比對還有 PAD SPACE 這一路（R5 的另一半）

`utf8mb4_unicode_ci` 是 **PAD SPACE** collation：SQL 端 `WHERE col = 'abc '` 會命中存著 `abc` 的列，但 PHP 端 `strcasecmp('abc', 'abc ')` **不等於 0**。失效方向與大小寫那一路完全相同——上游 SQL 找得到原交易、下游閘門比不中 → 放行再退一次，只是繞過手法從改大小寫換成**加一個尾隨空白**。

- ✅ **新建表**的冪等鍵欄位一律 `->collation('utf8mb4_bin')` ＋ PHP 用 `===`（對齊 `shared-table-contract` skill 的「字串鍵一律 bin」慣例）。
- ✅ **既有 ci 欄位**：兩側都先 `trim()` 再比，或改用 bin。
- ⚠️ 目前主要路徑靠 Laravel 全域的 `TrimStrings` middleware 兜底，但那是**隱性依賴**：自行解析 raw body 或在 middleware 之後 `$request->merge()` 的 vendor（FumaSW、MegaWin 這類先解密再合併的）繞得過去。

## 取消與退款（R11～R15）

退款是這批事故裡財損最集中的路徑——已證實的生產超退與憑空入帳都在這裡。

### R11｜終態守衛必須抽成單一方法，掛在每一條會動錢包的分支

- **位置**：`cancel`／`refund`／`settle`／`complete`／`adjust`／`releaseReserve`——**所有**會動錢包的分支。
- 🚫 **禁止**在多個分支各留一份守衛實作。漏掉任一條，就能用「先送 CANCEL_ROUND（bet 歸零）再送 CANCEL_TRANSACTION」這種**可主動編排的序列**，在已退光的注單上再退一次，bet 被扣成負數。
- ✅ 抽成一個 `checkBetRecordNotRefunded()` 之類的方法共用（`TTHSWController` 以此收編**六處**：`processWin`／`processCancelRound`／`processCancelTransaction`／`processAdjust`／`processReleaseReserve`／`complete`）。
- ⚠️ **補帳命令改過的注單狀態也是終態**：`bet-record-recovery:*` 退款後把注單標成 REFUND，三方遲到的結算若不擋，會把 status 改回進行中並派彩＝雙重入帳（`App\Vendors\FumaSW\Libs\Settle::lockAndGetPlaceBetRecord()` 對 REFUND 拋錯即為此）。

### R12｜三方帶來的金額與我方紀錄不符一律拒收

- **位置**：讀請求金額之後、寫注單之前。
- 🚫 **禁止**用請求帶來的金額覆寫注單金額。注單金額**以我方紀錄為準**，不接受三方覆寫。
- **後果**：`changeBalance()` 是以「前後注單差額」推算錢包變動的，注單金額一旦被請求覆寫，退多少就由三方說了算。Acewin 修正前寫的是 `$betRecord->bet = $bet`（請求金額），**請求帶與原注金相同的值時差額為零**，於是「取消投注」只把 status 改成 ROLLBACK、**餘額原封不動**（`13bbd7f69` 記載之修正前行為：扣 10.00 後撤單餘額停在 90.00；現行迴歸測試見 `AcewinCancelBetTest`）。
- ✅ 金額不符即拒收並記 warning（`AcewinController` 回 `errorCode=3`）。**比較一律顯式帶 scale 4**——`Common::numCompare` 預設 scale 是 10，而注單金額欄位是 `decimal(18,4)`；藍本見 `RgswController::AMOUNT_SCALE`。兩側運算元若已用 `numAdd`／`numDiv(..., 4)` 正規化到 4 位則可省。

### R13｜退款前必須驗證「原下注真的扣過款」，並對退款額設上下界

- **位置**：`refund` 分支的**第一個**檢查，且排在錢包上鎖之前（被拒的退款不該持有錢包列鎖）。
- **攻擊路徑**：故意讓下注在餘額檢查階段被拒（注單已建、`bet=0`、deal record 未 `applied`）→ 三方逾時仍送 REFUND → 由 `0 → −X` 算出 **+X 憑空入帳**，玩家沒下注卻拿到錢。PTSW 於 2026-09-05 事故窗**多付 253.00（5 筆）**。
- ✅ 驗「這筆下注真的入帳過」再退：PTSW 用 deal record 的 `applied_at` 非空（⚠️ `applied_at` 是 `ptsw_deal_records` 專有欄位，不是通用欄位；新表若要用同一判準須自行建欄），沒有等價欄位時退而求其次驗注單 `bet > 0`。
- ✅ 退款額必須為正數，且不得大於剩餘 bet——否則 bet 被扣成負數，等於退還玩家沒下過的錢（藍本見 `RgswController` 的上下界檢查）。

### R14｜取消／退款的注單欄位處置

取消投注時 `bet`／`valid_bet`／`win`（部分 vendor 的派彩欄命名為 `payout`）／`win_lose` **一律歸零**，退款金額交由 `changeBalance()` 依前後注單差額推得。

- 不歸零 ⇒ 被撤注單仍以原金額進 TBR → SBR → SR11／SR12、三層代理返利與提款流水門檻（AFB 事故日實測讓 SR12 顯示 143.30 vs 三方後台 126.90）。
- 把退款當派彩寫進派彩欄 ⇒ **禮碼投注會被退成真金**。
- ✅ 既有正確寫法可對照：Ace333SW、Astar、**AiGamingSW 的 `cancelBet()`**、CosmoPlaySW、DstPlaySW。
- ⚠️ AiGamingSW 另有一條 `settle` 的 `resultType === 'CANCEL'` 分支是**刻意只歸零 `valid_bet`、保留 `bet`**（本金以 `payoutAmount` 退回、不計有效投注），抄的時候別抄到那條。
- 📌 結算**之前**就取消、TBR 尚未建立的情形不需補 TBR 歸零，見本檔「total_bet_record 建立時機（預設結算才建）」。

### R15｜部分退款不可一律翻終態

支援部分退款的協定，退一部分之後**注單要留在原狀態**，退完才翻 REFUND。

- 🚫 一退就翻終態的話，同一局的第二筆合法部分退款會查不到注單（狀態解析不收 REFUND）→ **少退**（`97a4fba7f`）。
- ⚠️ 但狀態一旦不再能表達「已處理」，它就不能兼任冪等依據了——**此時必須同時補上內容指紋守衛**（見 R7），否則重送會重複入帳。
- 部分退款時 `valid_bet` 與 `bet` 同步遞減、以 0 為下限；扣到負數即代表退了玩家沒下過的錢（見 R13 的上下界）。

### 拒絕路徑若仍留下紀錄，必須打我方標記

被拒絕的請求如果仍會落地一筆事件（例如 deal record 不回滾），它會與**生效的**事件同形，下游全部誤判：

- 對帳命令把它算進 `SUM(bet_amount)` → 該局 `expected_bet` 被沖成 0 → **真正漏記的注單漏列**
- 冪等閘把它當成「已退過」→ 之後的合法退款被永久誤擋

✅ 一律加我方標記（`_rejected`），並讓**所有下游讀者**（守衛、對帳 SQL、補帳命令）同步排除它（`aed9375a4`）。

⚠️ 兩個配套：
1. **拒絕分支要排在任何會改寫欄位的計算之前**。`resolveSettlementOutcome()` 會把 REFUND 的 `win_amount` 改成正數，提前呼叫會讓被拒事件 `save()` 時把髒值落地，破壞「退款事件 `win_amount` 恆為 0」的不變式。
2. 對 TEXT 存 JSON 的欄位做 `NOT LIKE` 排除時**必須同時 `whereNull`**——`NOT LIKE` 對 NULL 回 NULL，生效的沖銷事件會被靜默濾掉，變成補過頭。

## vendor_info.rate 換算（單錢包回調的金額轉換，易漏且易踩雙重換算）

`vendor_infos.rate` 代表平台幣值單位與廠商幣值單位的比例（例：`1:1000`）。單錢包回調收付的金額一律是**廠商單位**，平台內部（`MemberWallet.balance`、BR 各金額欄位）一律是**平台單位**，兩者互轉規則：

| 方向 | 換算 | 使用時機 |
|---|---|---|
| 廠商 → 平台 | `Common::numDiv($vendorAmount, $rate, 4)` | 回調收到的 `bet_amount` / `win_amount` 等下注派彩金額，存入 BR／參與 `changeBalance()` 運算前 |
| 平台 → 廠商 | `Common::numMul($platformBalance, $rate, 4)` | 回應給廠商的 `balance` / `beginBalance` / `endBalance` 等餘額欄位 |

**地雷：`WalletService` 兩支語意相近的取餘額方法，換算行為不對稱**：

- `getSingleWalletBalanceByMember(VendorInfo, Member)`：**內部已經**乘上 `vendorInfo->rate`，回傳值可直接放進回應，**不可再乘一次**。
- `getMemberLockedWallets(...)->getTotalBalance()`（配合 `changeBalance()` 扣款／派彩用的鎖定錢包）：**不會**換算，回傳純平台單位，組回應前必須自己 `Common::numMul(..., $rate, 4)`。

兩者都叫「取餘額」，但只有前者內建換算。搞混方向（該乘的沒乘、或兩處都用了會自動換算的那支又外面多乘一次）會產生兩種常見錯誤：`rate=1` 時完全看不出來（相除相乘都是恆等），只有正式或測試把 `rate` 設成非 1（如 `1:1000`）才會炸開，且炸開時金額差 1000 倍量級，容易被誤判成別的 bug。

**驗證方法**：`vendor:add_vendor_info` 互動時 `rate` 提供 `1:1` 與 `1:1000` 兩個選項，**新 vendor 上線至少要用非 1:1 的 rate 測一輪** `getBalance` 與扣款／派彩回調，兩邊金額都要吻合換算比例，不能只在 `1:1` 下驗證就視為過關。

## 錯誤碼與回應（R22～R26）

守衛擋下之後回什麼給三方，決定了三方會不會再送。擋住了但三方不認，等於沒擋——還會放大併發。

### R22｜錯誤碼依「三方收到後的行為」挑，不依語意貼切

- 判準是**這個碼會讓三方停止重送、還是繼續重送**，不是哪個碼語意最貼切。行為未經證實的碼不要啟用。
- 🔴 **鎖／逾時／衝突類失敗必須回可重試碼**。PTSW 的結算撞同局鎖原本一律回不可重試的 `ERR_TRANSACTION_DECLINED`，PT 收到就不再送這筆結算 → 注單永遠停在 `status=1`、TBR 不建，**而下注款已扣＝永久損失**（`c3541a46c`）。
- 🔴 分派錯誤碼**用 `match` 不用三元式，且 `default` 必須落在可重試側**。三元式的 fallthrough 會讓日後新增的第三種 action 默默拿到不可重試的碼。
- ✅ 沿用「行為已知」的碼：SpadegamingSW 判成重送時回 `code 0`（其 `api.md` 明訂回 109 會讓 SG 持續重送）；TTHSW 刻意沿用線上已在回的 502／403，而非語意更貼切但行為未知的 503。

⚠️ 例外方向：`bet` 撞同局鎖**維持不可重試**——撞鎖代表同局另一筆正在處理，讓 bet 重試可能變成重複下注。可重試只給結算／取消類（見 [deliberate-decisions.md](deliberate-decisions.md)「錯誤碼」）。

### R23｜冪等擋下後的回應必須與首次成功同形

- 三方常以回應內容（而非 HTTP 狀態）判定成功。WudiSW 的重複結算依文件回 `code 1000`，但走了 `errorLogAndResponse` 不帶 `data`，三方解析失敗、無法結單、**持續重送**（`47672d5bf`）。
- ✅ 回應要帶齊三方判定成功所需的欄位與**當下餘額**。既有藍本：`TTHSWController::originalTransactionCanceledResponse()`（帶既有撤單當時的餘額）、`SpadegamingSWController::buildDuplicateResponse()`、RGSW 的 `duplicateCorrectionResponse()`、以及 1062 撞號時回當前餘額。

### R24｜回給三方的 ID 必須自始至終取同一張表

注單表與 deal record 都是自增整數、值域必然重疊。同一個回應欄位在不同分支取不同表的鍵，三方拿它對帳就會指到不同表的列（`0c6098c2f` 的 `ExternalTransactionId` 一律取注單 id）。

### R25｜回應期限（response deadline）

**前提**：僅適用「三方等不到回應會自行取消該筆、且**不補發**取消請求」的協定。會補發取消的協定不需要這道，硬加反而製造漏帳。

- 取到錢包鎖之後，量測「自 PHP 收到請求起」的耗時（以 `REQUEST_TIME_FLOAT` 起算），超過三方 deadline 即拋例外讓交易 rollback 並回錯誤，**不入帳**。
- ✅ 藍本 `b74c9c3af`：TTHSW 的 `RESPONSE_DEADLINE_MS` 預設 5000、可由商戶 `info` 覆寫。實證單號我方 −70／三方 −35 的差異即此成因。
- ⚠️ 那個秒數是 TTH 特有的，**不要照抄數值**，依各家 api.md 的 timeout 規格定。

### R26｜守衛觸發一律記 warning，帶齊 member_id 與金額

任何守衛判定「這筆是重送／該拒收」時，一律記 warning 並帶齊 `member_id`、金額與判定依據。

- 三種冪等機制都可能誤擋（把合法的第二筆同額調整當成重送），事故當下要能**直接從 log 算出「少付了誰、少付多少」**，不必回頭撈注單。
- ✅ 目前唯一符合這個標準的藍本是 `RgswController` 的重複判定 log。
- ⚠️ 既有 vendor 尚未拉齊（TTHSW／SpadegamingSW／Acewin 的守衛 log 缺 `member_id` 或金額），**不要照抄它們的 log 寫法**；新接一律照本節。
- 🔴 payload 進 log 前一律經 `Common::maskCredentials()`、header 經 `Common::flattenHeaders()`，遮罩鍵清單以 `MaskTrait` 為唯一來源，**禁止在呼叫端另立清單**（會 drift）。注單 `info` 則刻意不遮，見 [deliberate-decisions.md](deliberate-decisions.md)「遮罩與 log」。

## 來源驗證與憑證（R16～R21）

回調是外部入口，R1～R15 的鎖與冪等規則全部建立在「這個請求真的來自該三方」之上。這一節先站住。

### R16｜每個接入的 vendor 至少要有一道有效來源驗證

兩道可選：**IP 白名單**（`vendors.whitelist`）或**驗簽／憑證比對**（`vendor_infos.info` 的 `SECRET`／`API_KEY`／`SECRET_KEY`）。

- 三方多數會提供固定的請求 IP 清單，填進 `vendors.whitelist` 即可；**少數不提供的，通常會給驗簽機制**。
- 🚫 **兩道皆空即為缺陷**，不可上線。該 vendor 靠哪一道、為什麼（例如三方明確表示無固定出口 IP），要寫進該 vendor 的 `integration.md`。
- IP 白名單的驗證入口是 `Common::verifyIPWhitelistByVendorId($this->vendorId)`，放在 `callAction()` 開頭（見本檔「回調 Controller 骨架」）。

⚠️ **兩個必須知道的放行分支**（`app/Helpers/Common.php`）：

| 分支 | 行為 |
|---|---|
| `vendors.whitelist` 為空 | `verifyIPWhitelistByVendorId()` 直接 `return true` |
| `config('app.env') != 'production'` | `verifyIPWhitelist()` 直接 `return true`（開發便利，正式環境不受影響） |

也就是說**白名單沒填等於這道不存在**，不會有任何錯誤或 log。這正是 R16 要求「至少一道」而不是「有呼叫驗證函式就好」的原因。

⭐ 正面事實：來源 IP 的取得是**防偽造**的。`Common::resolveClientIp()` 先確認 `REMOTE_ADDR` 落在 trusted proxy CIDR 內，不在就直接回它、**根本不看 `X-Forwarded-For`**；在的話才從 XFF 右端往左找第一個非 trusted 的 IP。所以白名單無法用偽造 header 繞過（前提是 `config/trustedproxy.php` 的清單正確）。

### R17｜憑證未設定時一律拒收並記 warning

```php
$apiKey = [REDACTED] ($this->vendorInfo->info['API_KEY'] ?? '');

if ($apiKey === '') {
    Log::warning($this->actionPrefix . 'api_key_not_configured', [
        'vendor_info_id' => $this->vendorInfo->id,
        'reason' => 'info.API_KEY 未設定，回調一律拒收；請補上該站商戶設定',
    ]);

    return false;   // 🚫 不可 return true
}
```

- 🚫 **禁止 `if ($credential === '') return true;`**。那讓「設定漏填」等同於**無聲地拆掉這道防線**——不會有錯誤、不會有 log，防線就沒了；而若該 vendor 的白名單也是空的（R16 的第一個放行分支），就是兩道皆無。
- ✅ 既有 fail-closed 可對照：`EpicWin`／`KissH5`／`MegaH5`／`MetaGamingSW`／`Slot4DSW`／`UUSlotSW`／`Vplus`／`WF`／`CosmoPlaySW`／`GMSW` 的 `checkSign`／`verifySign`／`isSignatureValid`。
- ⚠️ 拒收的回應走該 vendor **既有的憑證錯誤碼**（AFBSw 的 `Invalid API key`、DstPlaySW 的 `code 107`），不要自創格式。

### R18｜憑證等值比對一律 `hash_equals()`

```php
return hash_equals($apiKey, (string) $request->input('key', ''));
```

🚫 禁止 `==`：兩個 numeric-string 在 PHP 8 仍走數值比較，且非等長比較有 timing 側漏。✅ 藍本 `AFBSwController::isApiKeyValid()`。

### R19｜驗簽之前的查詢屬未認證輸入，一律綁定參數

有些協定要先用請求裡的識別碼查出商戶、才能拿到金鑰解密驗簽。**那一步的輸入是未經任何認證的**，風險等級與驗簽之後完全不同。

- 🚫 禁止把它內插進 `whereRaw`／`DB::raw`。FumaSW 曾把 `dc` 字串內插進 `whereRaw`，而該步驟先於解密驗證、該 vendor 六站白名單皆空——等於未持任何憑證即可改寫 SQL 語意（`63d518287`）。
- ✅ 一律綁定參數；查無商戶時先存區域變數判空再指派，`first()` 的 null 直接指派給 typed property 會先拋 TypeError，讓後面的業務錯誤永遠到不了。

### R20｜授權檢查排在取列鎖與存在性查詢之前

🚫 授權若排在查注單之後，未授權請求可由回應碼差異當成「這個 round 存不存在」的探測工具，而且**會先取到注單列鎖**（可作 DoS）。✅ 藍本 `AstarController::authorizeGamehall()`（`265c66f32`）。

### R21｜以注單反查帳號時限縮在該商戶範圍內

本檔「建立遊戲帳號（createAccount）」的紅線要求動錢回調用「round → 注單 → `vendor_username` 反查帳號」定位玩家。**那個反查必須加上 `vendor_info` 條件**，否則他站的憑證可以動到本站的注單（跨商戶沖帳）。取消投注與退款（cancel／refund）帶了玩家 id 時再比對一次，防跨玩家沖帳；派彩類回調（settle、settleChange）例外，只比 `bet_id`，理由同上。

### 空 operatorId 的既有語意（不要一刀切改掉）

`BaseController::getVendorInfo('')` 對空 `operatorId` 會退回「該玩家所屬商戶」。這個語意目前有 **10 支 controller 刻意在用**，🚫 **不可全域改成 fail-closed**，會一次打斷全部十支。

但這個語意也意味著：**缺憑證時它不會報錯，只會靜靜地退回一個商戶**，等於整段跳過憑證比對。所以新接 vendor 若三方會帶商戶識別碼，就**明確傳進去**、缺漏即拒收（`48defb1b7` 對 Acewin／Ace333SW 即如此），不要依賴這個 fallback。

## 投注前檢核（帳號狀態 + 投注資格）

在**所有**會讀取或異動餘額的回調方法（`balance`、`bet`、`settle`、`refund` 等）最頂端，必須驗證帳號是否停用；扣款類方法另須驗證會員是否具備下注資格。下方各 `errorResponse` 的錯誤碼一律對照 `api.md` 的錯誤碼表。

### (1) 遊戲商帳號停用檢核（`account->status`）

涵蓋查詢餘額在內，所有涉及餘額讀取或異動的回調最頂端：

```php
if ($this->account->status == 0) {
    return $this->errorResponse(/* 依 `api.md` 對應「玩家不存在/停用」的錯誤碼 */);
}
```

> `status == 0` 代表遊戲商帳號被 activity 系統注銷，必須拒絕後續操作。

### (2) 會員下注權限檢核（`$this->verifyBettability` + `ShouldVerifyGameBettability`，強制）

僅針對**會扣餘額**的下注方法（`bet`/`debit`/`placeBet`/`betAndSettle`/`rollout`），**`getBalance`、純結算 `settle`、`refund` 不需要**。所有單一錢包 Controller **一律**：

1. 實作 `ShouldVerifyGameBettability` 介面（無條件，不看廠商是否自行管控遊戲開關）。
2. 呼叫 `BaseController::verifyBettability($gameCode)`（protected，繼承即有；參數是**遊戲代碼字串**）。
   ⚠️ 這支方法直接定義在 `BaseController`，Controller 端不需要、也不可以 `use` 任何 trait 取得它。
3. 🔴 **呼叫位置：交易內、`getMemberLockedWallets()` 之後**。`verifyBettability()` 會查會員／商戶／遊戲等表，放在鎖會員之前會把 REPEATABLE READ 快照定在等鎖之前，讀不到別人剛提交的狀態（R1 同一個理由，`target/tickets#4297`）。

```php
use App\Exceptions\BetException;
use App\Http\Controllers\External\SingleWallets\Contracts\ShouldVerifyGameBettability;

class FooController extends BaseController implements ShouldVerifyGameBettability
{
    public function bet(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            // 交易內第一個 SQL 必須是鎖會員列（R1）
            $walletDto = (new WalletService)->getMemberLockedWallets($this->member, $this->vendorInfo, $this->account);

            // 必須在鎖住會員之後才驗：放在鎖之前會讀到等鎖前的舊快照（target/tickets#4297）
            try {
                $this->verifyBettability((string) $request->input('GameCode', ''));
            } catch (BetException) {
                return $this->errorResponse(/* 依 `api.md` 對應「玩家凍結/不可下注」的錯誤碼 */);
            }

            // ...扣款
        });
    }
}
```

> 既有 vendor 兩種位置都有（AcewinSW／UUSlotSW／OneBXSW／Rcb988／SpadegamingSW 已搬進鎖後，其餘仍在交易外），**新接一律照鎖後這條**，不要拿還沒搬的那幾支當範本。
> 帳號停用檢核（上一小節的 `account->status`）則**維持在交易外**（`callAction()` 或方法開頭）：它只讀自己的帳號列、不涉及餘額判定，不需要等會員鎖。

`verifyBettability()` 內部即 `BetHelper::assertMemberAbleToBet($this->member, $this->vendorInfo, $game, $this)`，固定把 `$this` 傳給第 4 個參數 `$caller`。因為 Controller 已實作 `ShouldVerifyGameBettability`，這會觸發簡化路徑：遊戲可投注性只查 `vendor_info_games.bettable`，**不再檢查** `game.status`、`vendorInfoGames.status`、代理遊戲配置這三項。

> **⚠️ 前提**：`vendor_info_games.bettable` 預設值為 `1`；若該廠商的遊戲清單同步流程（`getGameList()`／`GameListSyncService`）從未維護這個欄位，下架/停用單一遊戲、或關閉特定代理的遊戲權限這類後台操作將對這個廠商失效（因為原本負責這件事的 `game.status`／`vendorInfoGames.status`／代理遊戲配置檢查已被跳過）。實作新廠商時如果沒有另外設計維護 `bettable` 的流程，等同於放棄這三層防護，需自行評估風險。

檢查項目（以 `app/Helpers/BetHelper.php::assertMemberAbleToBet()` 為準，共 11 項，按實際執行順序）：會員狀態（鎖定／未驗證）、商戶狀態、遊戲存在、遊戲商狀態、**遊戲商維護時段**、**代理鏈維護**、遊戲可投注性（簡化路徑只看 `bettable`；非簡化路徑才走 `assertRegularGameAvailability()` 查 `game.status`／商戶遊戲配置／代理遊戲配置）、代理鏈的商戶可用性（`VendorService::getVendorInfoIds()`）、代理鏈鎖定、鎖分模式（flow limit）、**未領取的活動獎勵**。

> ⚠️ **第 11 項「未領取的活動獎勵」**（領獎提醒）只在代理設定 `AgentCustomizedSettingKeyEnum::PRIZE_REMIND = 1` 時生效。那家代理開了這個設定時，會員有獎沒領就**整個下注被擋**（`BetException: Member has unclaimed prize`）——排查「某家代理突然全線不能下注」時要想到它，不要只往遊戲商設定找。

完整清單、排錯關鍵字與建議錯誤碼對照見 `docs/單一錢包遊戲接入流程/2-開發遊戲商Driver.md`。

### (3) bet + payout 合一 API 的特例（`betAmount > 0` 才檢查）

部分遊戲商的單一 API 同時承載投注與派彩（如 RectangleSW 的 PayoutTransactionV1）。`betAmount = 0` 代表純結算（free spin 派彩等），不應被 (1)(2) 阻擋：

```php
$betAmount = (float) $request->input('betAmount', 0);

if ($betAmount > 0) {
    if ($this->account->status == 0) {
        return $this->errorResponse(/* 停用錯誤碼 */);
    }

    $gameCode = (string) $request->input('gameCode', '');
    try {
        $this->verifyBettability($gameCode);
    } catch (BetException) {
        return $this->errorResponse(/* 不允許下注錯誤碼 */);
    }
}

// 冪等判斷...
```

---

## 同 round 多次 bet 必須累加，禁止覆寫（重要）

PT live casino（21 點 split/保險、百家樂多手）、PP slot multi-bet、其他支援單一 `gameRoundCode` / `roundId` 多次 placeBet 回調的遊戲商，**bet 階段 BR.bet / BR.valid_bet / BR.win_lose 必須累加，不可覆寫**。

```php
// ❌ 錯誤：覆寫前面 N-1 手金額（PTSW 初版 bug，導致 multi-bet 注單 turnover 偏低）
$betRecord->bet       = $betAmount;
$betRecord->valid_bet = $betAmount;

// ✅ 正確：累加
$betRecord->bet       = Common::numAdd($betRecord->bet, $betAmount, 4);
$betRecord->valid_bet = Common::numAdd($betRecord->valid_bet, $betAmount, 4);
```

**錢包扣減安全性**：若 vendor BetRecord 的 `getWin()` fallback 為 `win_lose + bet`（多數 vendor），`changeBalance` 計算的 walletChange 會化簡為 `winLoseChange + chipPaidChange`，與 bet 累加大小**無關**，玩家錢包不受影響。

**上線檢核清單**（multi-bet vendor 必驗）：

- [ ] placeBet 階段 BR.bet / BR.valid_bet 用 `Common::numAdd` 累加，不是 `=`
- [ ] 新建注單時 BR.bet=0、BR.valid_bet=0（讓累加從 0 開始）
- [ ] 必須有 `{Name}MultiBetTest` 測試：同 `gameRoundCode` 連續兩次 bet 後 BR.bet 為兩次之和（參考 `PpswMultiBetTest`、`PtswMultiBetTest`）
- [ ] settle 階段建立/upsert TBR 時 valid_bet 取最終累加值（不是單筆金額；TBR 建立時機見本檔「total_bet_record 建立時機（預設結算才建）」）

## settlement / refund 的 deal_records 沖銷模式

部分單錢包遊戲商在 settlement / refund 階段不僅更新 BR，還在 `deal_records` 寫入「沖銷事件」（type=`gameRoundResult` 或 `result`、`bet_amount = -placeBet金額`），用來在事件流上對齊 vendor 視角的 turnover：

```
placeBet     → deal_record: type='bet',             bet_amount=+X, applied_at=Carbon::now()
settle close → deal_record: type='gameRoundResult', bet_amount=0,  applied_at=NULL
refund       → deal_record: type='gameRoundResult', bet_amount=-X, applied_at=NULL  ← 沖銷
```

**對帳工程影響**：
- **不可只用 `type='bet'` 重建 BR.bet / valid_bet** — refund 注單在 BR 端 settle 階段已累加 -X 沖銷成 0，但「全 deal_records SUM」會自然反映這個 net 為 0 的狀態
- 詳見 `reconcile-bet-records` skill §2.1

## INSERT-first 冪等模式（推薦給 1 round 多 transactionNo 的 vendor）

當 vendor callback 每筆都有獨立 `transactionNo`，且同一 `roundID` 會帶來多筆（Live Baccarat 多碼下注、多次派彩、Refund 補單），最乾淨的冪等設計：

1. 建立 vendor 專屬 `{tname}_deal_records` 表（與全域 `deal_records` 不同），每筆 callback 寫一筆
2. `reference_no` = `transactionNo`，**`UNIQUE` 約束**作為唯一冪等防線
3. INSERT 放在 `DB::transaction` **closure 開頭**，業務邏輯之後
4. 業務錯誤（餘額不足 / round 找不到）一律 `throw DomainException` 觸發 rollback
5. 外層 `catch QueryException(1062)` 回原 balance（重送的冪等成功）
6. 外層 `catch DomainException` 回 errorResponse（讓 vendor 重送）

骨架：

```php
try {
    return DB::transaction(function () use (...) {
        $dealRecord = $this->dealRecordModel::create([
            'reference_no' => $transactionNo,
            'bet_id'       => $roundID,
            'bet_record_id' => null,   // 稍後回填
            // ...
        ]);

        $walletDto = (new WalletService)->getMemberLockedWallets(...);
        if (Common::numCompare($walletDto->getTotalBalance(), $amount) < 0) {
            throw new \DomainException('Insufficient balance');
        }

        $betRecord = ...;
        $this->changeBalance(...);

        $dealRecord->update(['bet_record_id' => $betRecord->id]);

        return $this->successResponse(...);
    });
} catch (QueryException $e) {
    if (($e->errorInfo[1] ?? 0) === 1062) {
        return $this->idempotentBalanceResponse($transactionNo);
    }
    throw $e;
} catch (\DomainException $e) {
    return $this->errorResponse($e->getMessage());
}
```

**為什麼 INSERT 不放在事務末段？**

舊 pattern（外層 `exists()` 快查 + 事務末 INSERT + 1062 catch）有兩層冪等檢查、且事務內 wallet lockForUpdate 等成本會在重送時被白白付出。INSERT 放開頭只有單一 UNIQUE 防線，重送會立刻 1062 fail-fast。

**為什麼業務錯誤要 throw？**

INSERT 在開頭意味 deal_record 已寫入；若用 `return $this->errorResponse(...)` 結束事務，deal_record 會被 commit，未來重送被 1062 catch 假冪等吃掉，但實際業務沒做（玩家損失）。throw 確保 rollback，下次重送可重試。

## Controller 共通樣板

針對有多個 callback method 的 vendor，以下 3 個慣例能讓 Controller 保持乾淨：

### (1) `callAction()` 集中前置驗證

若多個 method 都跑同一套（IP 白名單 / playerID 解析 / 帳號狀態），用 Laravel `callAction()` hook 集中：

```php
private const array METHODS_REQUIRE_PLAYER = ['balance', 'transfer'];

public function callAction($method, $parameters)
{
    if (!Common::verifyIPWhitelistByVendorId($this->vendorId)) {
        return $this->errorResponse('IP not allowed');
    }
    if (in_array($method, self::METHODS_REQUIRE_PLAYER, true)) {
        $request = $parameters[0];
        try {
            // 共用帳號組裝：解出 {vendor}_account / member / vendor_info
            $this->setUpVendorAccount(thirdPartyUsername: (string) $request->input('playerID', ''));
        } catch (VendorAccountException|MemberNotFoundException|VendorNotFoundException) {
            return $this->errorResponse('Player not found');
        }
        if ($this->account->status == 0) {
            return $this->errorResponse('Player account is disabled');
        }
    }
    return parent::callAction($method, $parameters);
}
```

各 action method 內部就不再重複這層檢查。

> **帳號解析一律走 `BaseController::setUpVendorAccount()`**，不要各家自寫一支 `resolveByPlayerId()`：2026-09 的 rcb988／uuslotsw／spadegamingsw／onebxsw 四支重構就是把自訂版本收斂回它（`006ded8dc`、`0005d3f4d`、`9940289ab`、`59610d7e0`）。需要額外條件（商戶代碼、幣別）時用第二個參數 `whereConditions`，不要另起一支查詢。
> 解析放在 `callAction()`＝交易外，正好滿足 R1「帳號、商戶、遊戲解析搬到交易外」。

### (2) 不重複 `logRequest()`

`routes/external/sw.php` / `tw.php` 已套用 `RequestLogger` middleware，自動記錄每個 callback 的 method / url / headers / params / response / SQL 統計。**禁止**在 Controller 內再呼叫 `$this->logRequest()`，純粹是雙寫。

### (3) log / error 標籤從單一欄位衍生，不寫死字串

兩側各有自己的欄位，不要混用：

| 位置 | 欄位 | 現況 |
|---|---|---|
| **Controller** | `$actionPrefix`（`BaseController` 宣告，`logRequest()` 與錯誤 log 都拼它）| 47 支單錢包 controller 全部有宣告 |
| **Driver** | `$vendorLabel`（自行宣告）| 目前只有 `E88Sport` 落實，新接照辦 |

```php
// Controller：BaseController 已宣告，覆寫即可
protected string $actionPrefix = 'Xxx_get_request_';

// Driver：log tag / error tag / Http::logAction 名稱都從它衍生
protected string $vendorLabel = 'VendorName';

$action = $this->vendorLabel . '_' . $endpoint;
throw new ApiException("[{$this->vendorLabel}][GetBalance] ...");
```

避免改名時要 grep 全檔。

## transaction_logs.type 作為錢包 session marker（單錢包）

### 核心設計

`transaction_logs.type` 在單錢包架構下**不是「事務型別」而是「錢包 session 標記」**：

| type | 業務語意 |
|---|---|
| 1 TO_VENDOR | 進入遊戲（轉帳錢包 session 開始） |
| 2 FROM_VENDOR | 離開遊戲 |
| 3 ORDER_DEPOSIT | 充值 |
| 5 WITHDRAWAL | 取款 |
| **27 SEAMLESS_WALLET_BET_SETTLE** | **用戶當前在單錢包遊戲 session 中**（所有 in-game 金額異動）|
| 28 SEAMLESS_WALLET_CANCEL | 保留 enum；`changeBalance` 不主動產生；由 vendor controller 在明確 rollback endpoint 顯式 `setType` |
| 29 SEAMLESS_WALLET_CHANGE | 保留 enum；同上 |

### changeBalance 不再推斷 type

`BaseController::changeBalance()` 將 `$transactionType` 永遠留為 `null`，由 `TransactionLogCreateService` fallback 為 `singleWalletBetType = 27`。**不論 in-game 行為是投注、派彩、累進派彩、bet 被退回、派彩被改小、派彩沖銷至 0**，一律歸 27 SETTLE。

### 延用機制天然契合 session marker

`TransactionLogCreateService::verifyAndCloseLastTransactionLog()` 對「同 type + 同 vendor + 未鎖定」連續寫入會**延用同一筆 log**：

```
若 新 type === 上一筆 type === 27
   && 上一筆 status != 1000（未鎖定）
   && 同 vendor（或同 sessionToken）
→ 延用上一筆：point += amount、balance = balanceAfter，不新建 log
否則
→ 鎖定上一筆 27（status=1000），新建 log
```

結果：**整段遊戲 session = 一筆 transaction_log**；切到不同 type（充值 3 / 取款 5 / 進新 vendor 1 / 離開 2）才會新建一筆。

### 28 / 29 何時寫入

只在 vendor 有明確的 rollback / refund / admin-modify endpoint 時，由 controller **顯式呼叫**：

```php
// 範例：app/Vendors/SpadegamingSW/TransferService.php:355
$this->transactionLogCreateService->setType(TransactionEnum::SEAMLESS_WALLET_CANCEL->value);
```

未來新 vendor 需要 fine-grained 區分時仿此模式，不要復活 `changeBalance` 內推斷。

### 對帳路徑

新設計下 `transaction_logs.type` 無法區分「投注 / 派彩 / 取消 / 改單」細項。fine-grained 對帳必須走：

- `{tname}_bet_records`：注單級狀態與金額
- `{tname}_deal_records`：事件級流水（含 refund 沖銷）

不要再依賴 `transaction_logs.type` 做業務分類查詢。

## total_bet_record 建立時機（預設結算才建）

`TotalBetRecordService::create()` 以 `(vendor_id, bet_record_id)` upsert；settle 用 `clone $oldBetRecord`（保留原 id）再 `create()` 即為更新同一筆，不會重複。建立時機依下列準則：

**準則：預設「結算才建 TBR」。唯一例外是遊戲商在輸錢時不發送 settle 回調（輸錢單只有 bet、永遠等不到結算），此型才在 `bet()` 當下 `create()` 建 TBR。**

| 情境 | TBR 時機 |
|---|---|
| bet / settle 分離回調，輸贏皆送 settle（絕大多數） | **結算才建** |
| bet + settle 合一回調（betNSettle / 單一 transactions 端點） | 該回調當下建（本來就是結算） |
| 結算週期長（體育賽事等隔日/多日後結算） | **結算才建** |
| **輸錢不送 settle**（輸錢單只有 bet 回調） | **bet 當下建**，settle（若有）upsert 同一筆 |

**為什麼預設是結算才建**：

1. **TBR 的語意是結算結果**，不是「已收到投注」。投注當下建會讓未結算 round 以 `win=0`、`payout_time` 為佔位值的半成品狀態，提前進入有效投注 / 流水 / 返水 / SBR 小時彙總的口徑。
2. **bet 當下建必須同步處理 rollback 歸零**，一旦漏做，被取消投注的注單仍以原始 bet / valid_bet 計入流水與返水。這是實際踩過的坑（Slot4D 先修 bet 後才補 rollback）——不建，就沒有這條必須成對維護的路徑。
3. 過去採「bet 當下建」的主因是**輸錢單永久漏關聯 transaction_log**，該根因已於平台層修掉（見下），不再需要靠提前建 TBR 迴避。

**確認 vendor 屬於哪一型**：對接時查 `api.md` 的結算回調規格，確認「輸錢（win=0）是否也會送 settle」。文檔沒寫死就以 UAT 實測為準，並把結論寫回 `integration.md`——這是決定 TBR 時機的唯一判準，不要用「是 slot 還是體育」去猜。

### payout=0 的 transaction_log 關聯（平台層保證）

輸錢結算（win=0）真金淨變動為 0，`increaseWalletBalance()` 不會建立新的 transaction_log 並回傳 0。`BaseController::changeBalance()` 在此情況下會改取本回合進行中（`status=999` STATUS_GAMING）的那筆 session log 一併回傳，呼叫端照常把回傳值交給 `TotalBetRecordService->create($betRecord, $transactionLogId)` 即可建立 pivot。**payout 為 0 的注單一樣要關聯 transaction_log**，否則它永遠不會出現在 SR14 現金紀錄 detail（API_Agent `ReportController@betDetail` 靠 `total_bet_record_transaction_logs` pivot 反查 TBR），這就是「detail 只出現有派彩的部分」的成因（2026-07 Slot4D 實例，target/tickets#2671）。

- **判斷依據是本回合真金投注額**（`currentBetAmount - rollingChip`）> 0，不是 `walletBalanceChange`
- **全禮碼投注（真金投注額 = 0）不關聯**：整回合不涉及現金，若掛到同 session 其他回合的現金紀錄底下，SR14 detail 會出現不存在的現金注單
- vendor Controller **不要自己撈 `status=999` 的 log**。接住 `changeBalance()` 的回傳值就好；自寫查詢會漏掉上面的禮碼判斷，是已知的複製貼上型缺陷（TTHSW `findOpenSessionTransactionLogId`）

**結算才建的實作要點**：

- settle 接住 `$transactionLogId = $this->changeBalance(...)`，`save()` 後呼叫 `TotalBetRecordService->create($betRecord, $transactionLogId)`
- 多段累進派彩：每段 settle 都 upsert 同一筆，`valid_bet` 取最終累加值（本檔「同 round 多次 bet 必須累加，禁止覆寫（重要）」）
- 取消投注（rollback / refund）發生在結算前、TBR 尚未建立時，不需要補 TBR 歸零

**「輸錢不送 settle」型的實作要點**（bet 當下建）：

> **尚未確認有哪些遊戲商屬於此型**——現有 vendor 未逐家盤點過，本節不列藍本。遇到疑似此型的 vendor，先依上述「確認 vendor 屬於哪一型」實測確立，再把該 vendor 補進本節作為藍本。**不要**拿既有「bet 當下建」的 vendor 反推它屬於此型：那多半是舊準則的遺留，不是輸錢無 settle。


- `payout_time` 填冪等佔位值 `'1970-01-01 00:00:01'`（非 null：`total_bet_records.payout_time` 無預設值，null 會 INSERT 漏欄位報錯；非 `00:00:00`：MySQL TIMESTAMP 合法下界為 1970-01-01 00:00:01 UTC）
- **`rollback` 必須與 `bet` 成對處理**：回滾路徑同樣接 `$transactionLogId` 並再 `create()`（upsert 歸零後的 betRecord），否則被回滾注單仍以原始 bet / valid_bet 計入流水、返水與 SBR 彙總

**既有 vendor 不主動拉齊**：既有 vendor 兩型都有，非 bug；要改動前先與 PM/QA 確認 SR14 對投注筆的顯示期望，勿單改一家造成不一致。新接 vendor 直接依上表準則決定。
