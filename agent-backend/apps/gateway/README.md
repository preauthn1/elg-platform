# @vben/gateway

## Description

代理後台的 Nitro gateway（代理層）。前端所有 `/proxy/*` 請求都先打到這裡，由它維護伺服器端 session（token 不落地瀏覽器）、加密簽名後轉發到真實 API（`API_URL` / `APIS_URL` / `API_INTERNAL`）。

- `routes/proxy/api/login.post.ts`：登入，建立 session
- `routes/proxy/me.get.ts` / `logout.post.ts`：session 查詢／登出
- `routes/proxy/[...].ts`：一般 API 轉發（依路徑前綴分流 api / agent/api）
- `routes/proxy/internal/[...].ts`：內網 API 轉發（不加密，dev 環境不可達）
- `middleware/02.agent-api-guard.ts`：`agent/api/*` 必須有已登入的 session 才轉發（未登入回 code 9998）；已停用的 `agent/api/login` 不論登入與否一律 403，路徑經正規化比對；含編碼、反斜線、多斜線、點段等歧義路徑一律 403

設定放 `apps/gateway/.env`（見 `.env.example`）。

## Running the app

開發時不需手動啟動——已整合在 vite 外掛（`viteNitroMockPlugin`）裡，跑 `pnpm dev:ele` 會一起在 5320 起來。

```bash
# development
$ pnpm run start

# production mode
$ pnpm run build
```
