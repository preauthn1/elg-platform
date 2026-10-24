---
type: Table
id: data-model/members
title: 會員
description: 'members 是會員主檔，記錄帳號、密碼、聯絡資料、餘額與驗證狀態，登入、充值、取款與錢包操作都讀寫它。'
domain: 會員
tags:
  - members
  - 會員主檔
  - 餘額
  - 帳號狀態
status: draft
generated:
  by: 'artisan:knowledge:scaffold'
  at: '2026-10-21T02:27:49+00:00'
evidence:
  - kind: code
    ref: /app/Models/Member.php
    at: '2026-10-21'
  - kind: migration
    ref: /database/migrations/2025_06_19_091321_create_members_table.php
entity:
  table: members
  model: App\Models\Member
  columns:
    - { name: id, type: 'int unsigned', nullable: false, comment: 系統編號 }
    - { name: parent, type: int, nullable: false, comment: 上層代理系統編號(User) }
    - { name: member_group_id, type: int, nullable: true, comment: 會員群組(member_groups.id) }
    - { name: username, type: varchar(32), nullable: false, comment: 姓名 }
    - { name: username_bak, type: varchar(45), nullable: true }
    - { name: email, type: varchar(60), nullable: true, comment: 電子信箱 }
    - { name: nickname, type: varchar(191), nullable: true, comment: 暱稱 }
    - { name: password, type: varchar(128), nullable: false, comment: 密碼 }
    - { name: password_md5, type: char(32), nullable: true, comment: 密碼MD5(風控比對同密碼帳號用，不參與登入驗證) }
    - { name: payment_password, type: varchar(128), nullable: true, comment: 支付密碼 }
    - { name: balance, type: 'decimal(18,4)', nullable: false, comment: 平台餘額 }
    - { name: balance_bak, type: 'decimal(18,4)', nullable: false }
    - { name: invitation_code, type: varchar(20), nullable: true, comment: 邀請碼 }
    - { name: device_id, type: text, nullable: true, comment: 推播設備號 }
    - { name: device_type, type: varchar(20), nullable: true, comment: 設備種類 }
    - { name: image, type: varchar(191), nullable: true, comment: 個人頭像 }
    - { name: first_name, type: varchar(100), nullable: true, comment: 英文名 }
    - { name: last_name, type: varchar(100), nullable: true, comment: 英文姓 }
    - { name: birthday, type: date, nullable: true, comment: 出生年月日 }
    - { name: mobile, type: varchar(32), nullable: true, comment: 聯繫號碼 }
    - { name: timezone_id, type: int, nullable: true, comment: 時區系統編號 }
    - { name: country_id, type: int, nullable: true, comment: 居住國家(FK_Country) }
    - { name: zip_code, type: varchar(10), nullable: true, comment: 郵政編碼 }
    - { name: district, type: varchar(32), nullable: true, comment: 省/地區/郡 }
    - { name: city, type: varchar(30), nullable: true, comment: 城鎮/城市 }
    - { name: address, type: varchar(191), nullable: true, comment: 地址 }
    - { name: register_country_id, type: int, nullable: true, comment: 註冊國家 }
    - { name: register_ip, type: bigint, nullable: true, comment: 註冊IP }
    - { name: register_ip_detail, type: text, nullable: true }
    - { name: register_domain, type: varchar(100), nullable: true, comment: 註冊網域 }
    - { name: type, type: tinyint, nullable: false, comment: '會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員' }
    - { name: previous_day_type, type: tinyint, nullable: false, comment: '前一天會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員' }
    - { name: status, type: tinyint, nullable: false, comment: '帳號狀態 0:未認證,1:正常,2:鎖定(密碼輸入錯六次, 系統自動變更)' }
    - { name: sys_lock, type: tinyint(1), nullable: false, comment: '系統鎖定; 1:鎖定, 0:非解鎖' }
    - { name: event_mode, type: int, nullable: false, comment: '鎖分模式綁定的 prize_contents.id；0 為未鎖定' }
    - { name: change_to_single_wallet, type: tinyint(1), nullable: false, comment: '代理是否由多錢包轉換為單一錢包0:否，1:是' }
    - { name: is_validate, type: int, nullable: false, comment: 是否通過身份驗證 }
    - { name: mobile_validate, type: int, nullable: false, comment: 是否通過手機驗證 }
    - { name: email_validate, type: tinyint(1), nullable: false, comment: '狀態 0:未認證,1:已認證' }
    - { name: last_login, type: datetime, nullable: true, comment: 最後登入時間 }
    - { name: last_login_ip, type: bigint, nullable: true, comment: 最後登入IP }
    - { name: first_deposit, type: datetime, nullable: true, comment: 首次儲值時間 }
    - { name: first_deposit_point, type: 'decimal(18,4)', nullable: true, comment: 首存金額(point，換算後實際入帳點數，非原幣別amount) }
    - { name: memo, type: varchar(191), nullable: true, comment: 方便技術記錄用 }
    - { name: has_2fa, type: tinyint, nullable: false, comment: 'Google驗證器開關, 0:關閉, 1:開啟' }
    - { name: ip_change_mobile_verify, type: tinyint, nullable: false, comment: 登入IP變更手機驗證開關 }
    - { name: ip_change_email_verify, type: tinyint, nullable: false, comment: 登入IP變更email驗證開關 }
    - { name: created_at, type: timestamp, nullable: false, comment: 建立日期 }
    - { name: updated_at, type: timestamp, nullable: false, comment: 更新日期 }
    - { name: admin, type: int, nullable: false, comment: 更新者ID }
    - { name: vworld_id, type: varchar(191), nullable: true, comment: vworld1.0的玩家編號(備用而已) }
    - { name: realname_validate, type: tinyint(1), nullable: false }
    - { name: last_activity_at, type: timestamp, nullable: true, comment: 最後請求API時間 }
  read_by:
    - App\Models\Member
  written_by:
    - App\Models\Member
---

<!-- knowledge:generated start -->
## 表

`members`

Model：`App\Models\Member`

| 欄位 | 型別 | 可空 | 說明 |
|---|---|---|---|
| `id` | int unsigned | 否 | 系統編號 |
| `parent` | int | 否 | 上層代理系統編號(User) |
| `member_group_id` | int | 是 | 會員群組(member_groups.id) |
| `username` | varchar(32) | 否 | 姓名 |
| `username_bak` | varchar(45) | 是 |  |
| `email` | varchar(60) | 是 | 電子信箱 |
| `nickname` | varchar(191) | 是 | 暱稱 |
| `password` | varchar(128) | 否 | 密碼 |
| `password_md5` | char(32) | 是 | 密碼MD5(風控比對同密碼帳號用，不參與登入驗證) |
| `payment_password` | varchar(128) | 是 | 支付密碼 |
| `balance` | decimal(18,4) | 否 | 平台餘額 |
| `balance_bak` | decimal(18,4) | 否 |  |
| `invitation_code` | varchar(20) | 是 | 邀請碼 |
| `device_id` | text | 是 | 推播設備號 |
| `device_type` | varchar(20) | 是 | 設備種類 |
| `image` | varchar(191) | 是 | 個人頭像 |
| `first_name` | varchar(100) | 是 | 英文名 |
| `last_name` | varchar(100) | 是 | 英文姓 |
| `birthday` | date | 是 | 出生年月日 |
| `mobile` | varchar(32) | 是 | 聯繫號碼 |
| `timezone_id` | int | 是 | 時區系統編號 |
| `country_id` | int | 是 | 居住國家(FK_Country) |
| `zip_code` | varchar(10) | 是 | 郵政編碼 |
| `district` | varchar(32) | 是 | 省/地區/郡 |
| `city` | varchar(30) | 是 | 城鎮/城市 |
| `address` | varchar(191) | 是 | 地址 |
| `register_country_id` | int | 是 | 註冊國家 |
| `register_ip` | bigint | 是 | 註冊IP |
| `register_ip_detail` | text | 是 |  |
| `register_domain` | varchar(100) | 是 | 註冊網域 |
| `type` | tinyint | 否 | 會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員 |
| `previous_day_type` | tinyint | 否 | 前一天會員類型; 0:一般會員, 1:份桶會員, 2:佔成會員 |
| `status` | tinyint | 否 | 帳號狀態 0:未認證,1:正常,2:鎖定(密碼輸入錯六次, 系統自動變更) |
| `sys_lock` | tinyint(1) | 否 | 系統鎖定; 1:鎖定, 0:非解鎖 |
| `event_mode` | int | 否 | 鎖分模式綁定的 prize_contents.id；0 為未鎖定 |
| `change_to_single_wallet` | tinyint(1) | 否 | 代理是否由多錢包轉換為單一錢包0:否，1:是 |
| `is_validate` | int | 否 | 是否通過身份驗證 |
| `mobile_validate` | int | 否 | 是否通過手機驗證 |
| `email_validate` | tinyint(1) | 否 | 狀態 0:未認證,1:已認證 |
| `last_login` | datetime | 是 | 最後登入時間 |
| `last_login_ip` | bigint | 是 | 最後登入IP |
| `first_deposit` | datetime | 是 | 首次儲值時間 |
| `first_deposit_point` | decimal(18,4) | 是 | 首存金額(point，換算後實際入帳點數，非原幣別amount) |
| `memo` | varchar(191) | 是 | 方便技術記錄用 |
| `has_2fa` | tinyint | 否 | Google驗證器開關, 0:關閉, 1:開啟 |
| `ip_change_mobile_verify` | tinyint | 否 | 登入IP變更手機驗證開關 |
| `ip_change_email_verify` | tinyint | 否 | 登入IP變更email驗證開關 |
| `created_at` | timestamp | 否 | 建立日期 |
| `updated_at` | timestamp | 否 | 更新日期 |
| `admin` | int | 否 | 更新者ID |
| `vworld_id` | varchar(191) | 是 | vworld1.0的玩家編號(備用而已) |
| `realname_validate` | tinyint(1) | 否 |  |
| `last_activity_at` | timestamp | 是 | 最後請求API時間 |
<!-- knowledge:generated end -->

## 做什麼

`members` 一列代表一個會員：在某個代理底下註冊的帳號，`parent` 記上層代理，`member_group_id` 記所屬會員群組。`balance` 是會員一般錢包的餘額，充值上分、取款建單、開分與洗分都會讀寫這個欄位。`status` 記帳號狀態（未認證、正常、鎖定），`first_deposit`、`first_deposit_point` 記首次充值的時間與入帳點數。這張表是登入、充值、取款與多數會員 App 操作共同讀寫的主檔。規則見相關規則。

## 相關規則

* [rules/currency-and-wallet-currency](../rules/currency-and-wallet-currency.md)
