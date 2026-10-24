# web-frontend — 工作規範

新前台（Nuxt 4 SSR + TS + pnpm），取代 `../WEB_Frontend/_source2`（Vue 2 / webpack 3）。

## 這是重構，不是重新設計

**行為的最終依據是舊專案 `../WEB_Frontend/_source2`。** 查得到就不要假設：

| 要確認的事 | 去讀 |
|---|---|
| 金額四捨五入還是捨去 | `_source2/src/main.js` 的 `roundDown`（答案是捨去） |
| 請求加密的式子與金鑰 | `_source2/src/utils/secret.js`、`_source2/config/env.js` |
| 某個路由存不存在 | `_source2/src/router/C13.js`、`C16.js`（最接近的兩個手機皮） |
| 某頁長怎樣、抓哪些資料 | `_source2/src/components/C13/Page/<Name>.vue` |
| 文案舊 key ↔ 新 key | 比對 `_source2/src/lang/` 與 `i18n/locales/` |

要保留的是**行為**，不是實作。舊專案的 sessionStorage 當全域變數、元件裡的品牌 if/else、
寫死的 hostname、複製整棵皮——這些都不要帶進來。旗標一律來自 `site.settings`。

未來其他皮會搬進同一個專案，所以邏輯放 `shared/` 與 `composables/`，皮只管呈現。

---

## 動手前先讀

| 要做什麼 | 事實在哪 |
|---|---|
| 頁面 / 元件 | `app/components/C17/Shell.vue`（版面）、`app/components/Ui/`（共用元件） |
| 接 API | `shared/api/endpoints/<domain>.ts` 的 zod 就是契約 |

---

## 專案鐵則

### 1. 金額一律走 `shared/utils/amount.ts`

decimal.js。**絕不**用 `+ - * /`、`Number()`、`parseFloat()`、`toFixed()` 算金額。
顯示一律**無條件捨去**（`decFloorFixed`）——進位等於顯示會員沒有的錢。命名用 amount 不用 money。

### 2. 瀏覽器送出的請求一律加密，不得退回明文

格式 `{ params, key }`：`params` = base64(AES-256-CBC(JSON))，`key` = `md5(params + API_MD5_KEY)`。
與舊前台 `callApi` 逐字元相同，`tests/unit/client-crypto.spec.ts` 鎖住這個等價關係。

- server 讀 body 一定用 `readClientBody(event)`，不要用 `readBody`
- 前端發請求走 `useApi().post()`；非 BFF 的同源端點用 `postRaw()`
- **不要自己寫 `$fetch(url, { body })`**，那會送明文、被 BFF 回 422
- 加密失敗要丟錯，不要 fallback 成明文

這層是**混淆不是保密**（金鑰必須讓瀏覽器讀得到）。真正的防護是端點白名單、zod、
密封 cookie 裡的登入態與限流。

`crypto.subtle` 只在 https 或 localhost 存在，走區網 IP 開發會加密失敗。

### 3. 全站只有一個 layout

`app/layouts/default.vue` → `C17Shell`。**不要開第二個 layout**：Nuxt 切換 layout 會把外殼
卸載重建，頂部列每次重掛就重打一次餘額。

- 滿版（自己控制留白）：`definePageMeta({ fullBleed: true })`
- 完全不要外殼：`definePageMeta({ layout: false })`

### 4. 文案一律走 i18n，不可硬寫

14 個語系。key 四段：`<domain>.<module>.<role>.<name>`，照 `i18n/locales/en.json` 既有的命名。

- `{name}` **必須當 vue-i18n 參數傳**：`t(key, { name })`。`t(key)` 回傳前就插值掉了，
  之後再字串取代來不及
- 品牌名走 `site.raw.title`，品牌差異用 `i18n/overrides/<brand>/`，不要為每個品牌開 key

### 5. 端點一定要登記

`shared/api/endpoints/<domain>.ts` 用 `defineEndpoint` 定義 request / response zod，
沒登記的 route BFF 直接擋。金額欄位用 `z.union([z.string(), z.number()])`，
**不要 `z.coerce.number()`**（後端送 4 位小數字串，轉了就掉精度）。

### 6. 不要複製貼上

**同一件事只能有一個出處。** 寫之前先找：

| 要做的事 | 已經有了 |
|---|---|
| 算／格式化金額 | `shared/utils/amount.ts` |
| 打 API | `useApi().post` / `postRaw` |
| 讀請求 body（server） | `readClientBody(event)` |
| 版面外殼 | `C17Shell` |
| 輸入框／按鈕／勾選／彈窗／抽屜 | `app/components/Ui/` |
| 秒數 → `HH:mm:ss` | `formatClock`（`app/utils/events-core.ts`） |
| VIP 等級門檻／權益文字 | `shared/utils/vip.ts` 的 `describe*` |

**第三份就是訊號。** 抽的方向：邏輯往 `shared/` 或 `composables/` 收，呈現差異留在元件。

不要用這些方式避開重複：複製一份改名字（`SignupFormV2`）、同名 export 放兩個檔案
（Nuxt 自動匯入會默默挑一個，`tests/unit/no-duplicate-exports.spec.ts` 擋著）、
把差異塞成一大包 `if (brand === ...)`。

### 7. 發現的問題要變成守門測試

修掉會重複發生的缺陷之後，補一支會紅的測試鎖住，不要只留註解。

---

## 完成的定義

```bash
pnpm lint && pnpm typecheck && pnpm test
```

三個都綠才算做完。動到 layout、server 或路由時再加一次 `pnpm build`（會抓到 SSR-only 的問題）。
