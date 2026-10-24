---
type: Command
id: apis/console/make-vendor
title: 建立遊戲商的檔案
description: '新接一家遊戲商時，產生 Model、Driver 骨架檔案並註冊進 config/vendor.php 的腳手架命令。'
domain: 遊戲與遊戲商
tags:
  - make-vendor
  - 遊戲商
  - 腳手架
status: draft
generated:
  by: 'script:knowledge-tools'
  at: '2026-10-22T03:01:54+00:00'
evidence:
  - kind: code
    ref: /app/Console/Commands/Maker/MakeVendorDriver.php
    at: '2026-10-22'
entity:
  class: /app/Console/Commands/Maker/MakeVendorDriver.php
  signature: 'make:vendor'
---

<!-- knowledge:generated start -->
## 入口

`php artisan make:vendor`

Command：`App\Console\Commands\Maker\MakeVendorDriver`

建立遊戲商的檔案

| 參數 | 種類 | 說明 | 預設 |
|---|---|---|---|
| `name` | 引數 |  |  |

排程：無
<!-- knowledge:generated end -->

## 做什麼

RD 要新接一家遊戲商時執行這支命令：依互動問答輸入是無縫錢包還是轉帳錢包、遊戲商名稱，產生該遊戲商的 Model 與 Driver 骨架檔案，並把它註冊進 `config/vendor.php`。找不到對應的 `VendorIdEnum` case 時會中止註冊並提示先補上該 case，避免整個應用在載入 config 時壞掉。規則見相關規則。

## 相關規則

* [rules/vendor-and-channel-management](../../rules/vendor-and-channel-management.md)
