# elg-tgbot-php

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`elg-tgbot-php`　|　文件 **145**　|　主语言：PHP 129, JSON 4, JavaScript 4

## 目录构成

```
app/Services/  — 24
app/Enums/  — 15
config/  — 14
app/DTOs/  — 9
app/Http/  — 8
app/Helpers/  — 8
tests/Unit/  — 7
lang/mm-my/  — 5
lang/en-us/  — 5
database/migrations/  — 4
public/vendor/  — 4
routes/  — 3
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`
- 前端入口：`resources/js/app.js`、`public/vendor/log-viewer/app.js`

---

## 原始说明

# 環境
Laravel 11
Mysql 5.7
PHP 8.3

# 設定
### Webhook
`https://example.invalid/bot{telegram_bot_id}/setWebhook?url={api_url}`

# Notes
## 訊息傳送流程

### Controller
1. Telegram server 打 Webhook，我方 parse 請求確認 chatId
2. **Lock key concurrent_locker_:botId_:chatId** (prevent spam, will be unlocked in job)
3. **If can't lock, response 200 to TG server** (drop it)
4. Add new handler job to job queue, and send lock key to job
5. Response 200 to TG server

### Job
1. Regist singletons
2. Cll **WebHookHandlService**
3. Unlock key concurrent_locker...

### WebHookHandlService
1. Verify actions
2. call ActionServices by action
3. return

