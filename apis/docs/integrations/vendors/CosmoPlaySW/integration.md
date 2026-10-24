# CosmoPlaySW 對接心得

> 本檔只記「文件＋程式碼查不到、實際接入才踩到」的落差。廠商原文規格見 [`api.md`](api.md)（Guide v2.6）。

## 1. 驗簽 header 實際帶在 `AuthorizationV2`，不是規格寫的 `Authorization`

`api.md` §API Security 寫所有請求的 JWT 帶在 `Authorization: Bearer <token>` header。

**實測**：CosmoPlay 請求經過的中間 gateway 會把標準 `Authorization` header 改寫成不相干的內部 token，真正符合規格（claims = 完整 request payload）的 JWT 實際帶在 `AuthorizationV2` header。

驗簽必須讀 `AuthorizationV2`，讀 `Authorization` 會驗簽全部失敗。見 `app/Http/Controllers/External/SingleWallets/CosmoPlaySWController.php::verifyJwt()`。

## 2. 比對 JWT claims 時不能用 `$request->all()`，要用 `$request->json()->all()`

`api.md` 規定 JWT 的 claims 必須由「完整 request payload」產生（見 §JWT with HS256 的 Go 範例：`claims[key] = value` 逐一塞入整個 payload map）。

**實測**：CosmoPlay 部分請求（例如 `/bet-result`）URL 上會帶 cache-busting 用的 query string（如 `nocache`），但簽 JWT 時的 payload 只包含 JSON body，不含 query string。

若驗簽時用 `$request->all()`（會合併 query string 到陣列），claims 比對必定失敗，誤判為驗簽失敗。必須改用 `$request->json()->all()`，只取 JSON body 部分比對。見 `verifyJwt()` 同一處。
