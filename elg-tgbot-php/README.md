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
