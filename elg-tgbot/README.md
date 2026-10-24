# elg-tgbot

> 本目录原始 README 见文末，本节为快照补充信息。

原始路径：`elg-tgbot`　|　文件 **84**　|　主语言：C# 60, YAML 11, JSON 9

## 目录构成

```
TG_Hook_API/Helper/  — 33
TG_Hook_API/Models/  — 13
helm-charts/tgbot/  — 12
TG_Hook_API/  — 7
TG_Hook_API/Controllers/  — 5
TG_Hook_API/Service/  — 5
TG_Hook_API/Filter/  — 2
TG_Hook_API/Asset/  — 2
TG_Hook_API/Properties/  — 1
.vscode/  — 1
DockerFiles/  — 1
```

## 关键文件

- CI/构建：`.gitlab-ci.yml`、`DockerFiles/Dockerfile`
- 服务端主程序：`TG_Hook_API/Program.cs`、`TG_Hook_API/Startup.cs`

---

## 原始说明

# TGBot
tg機器人 (2023/3)


## 建bot注意事項
- 需設定webHook url (限制https) https://example.invalid/bot{你的BOT_TOKEN}/setWebhook?url=http://example.invalid/bot_hook
- bot settings > allow group 需要關掉

- @VWMTestBot https://example.invalid/bot7105762646:AAGBtP0gHzuRLsCsjb9N-KiJvbWAHCv090g/setWebhook?url=https://example.invalid/TGBot/v2/VWMTest
- @casino365th_dev_bot https://example.invalid/bot6169247745:AAHxeSlij2E_q9VnL5ZkWsNgaROqL4zxkYU/setWebhook?url=https://example.invalid/TGBot/v2/Casino365TH

## 各種符號

// 🔥❗️⚠️💳✅💯🌐💰📃🔑⏭🎁🎰💎👇👩‍💼 👀👩🏻‍🔧❎🔄↩️👥➖➕🎮🕔❌

## set commands
-

