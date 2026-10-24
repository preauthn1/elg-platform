# gffv3-web-frontend

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`gffv3-web-frontend`　|　文件 **675**　|　主语言：Vue 332, TypeScript 289, JSON 42

## 目录构成

```
app/components/  — 213
app/composables/  — 134
app/pages/  — 115
tests/unit/  — 51
i18n/overrides/  — 26
shared/api/  — 17
app/stores/  — 17
shared/utils/  — 16
i18n/locales/  — 14
server/api/  — 10
app/utils/  — 10
server/utils/  — 9
```

## 关键文件

- CI/构建：`Dockerfile`、`.gitlab-ci.yml`

---

## 原始说明

# web-frontend

新前台：Nuxt 4 SSR + TypeScript + pnpm。

動手前先看 [`CLAUDE.md`](CLAUDE.md)。

## 開發

```bash
pnpm install
cp .env.example .env   # 填 NUXT_API_* 與 NUXT_SESSION_PASSWORD
pnpm dev               # http://localhost:8080
```

本機沒有正式域名，用 `NUXT_DEV_HOST_OVERRIDE` 指定所有請求視為哪個 Host。
`GET /api/_site`（僅開發環境）可看解析出來的站台。

## 檢查

```bash
pnpm check       # lint + typecheck + test
```

## 部署

`Dockerfile` 多階段建置，執行 `node .output/server/index.mjs`（port 3000）。
所有品牌共用同一個 image，差異全在環境變數。

