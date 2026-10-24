# elg-tgbot

原始路径：`elg-tgbot`　|　文件：**84**　|　主语言：C# 60, YAML 11, JSON 9

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
- 依赖清单：`TG_Hook_API/TG_Hook_API.csproj`

## 主要文件

- `TG_Hook_API/Service/VworldTgBotService.cs`
- `TG_Hook_API/Service/VworldPlatformApiService.cs`
- `TG_Hook_API/Helper/APIRequest/APIRequestHelper.cs`
- `TG_Hook_API/Helper/Extensions/ExtensionOfString.cs`
- `TG_Hook_API/Helper/Extensions/ExtensionOfCollections.cs`
- `TG_Hook_API/Helper/Telegram/BaseImplement/BaseTelegramUtility.cs`
- `TG_Hook_API/Controllers/Others/TGWebHookV1Controller.cs`
- `TG_Hook_API/Helper/ConfigSetting/SettingHelper.cs`

## 说明

该工程为平台的一个组件。仅收录文本源码与配置，图片、音频等二进制资源未收录；凭据、密钥、内网地址与域名已按实际内容替换。

