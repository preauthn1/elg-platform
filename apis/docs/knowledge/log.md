# 知識庫異動紀錄

只記 bundle 層級的事：建立、遷入、作廢、改名。單份文件的規則改動與依據，看該檔的 git log。

## 2026-10-21

* **Restructured**: 知識庫改成原子知識五區（architecture、apis、rules、data-model、decisions），一份文件講一件事；舊的「功能頁」格式與功能短編號不再使用（決策見 [decisions/0004-ssot-lives-in-git-knowledge-bundle](decisions/0004-ssot-lives-in-git-knowledge-bundle.md) 的修訂）。
* **Migrated**: `docs/adr/` 四份搬進 `decisions/`；首批自 Outline 遷入的三頁與回填第一批（2026-09-01 起合入 master 的 03 金流 MR）的 15 頁，合計 18 頁改寫成 `rules/` 的 18 份、380 條規則，編號沿用（SSOT-n 改 R-n）。
* **Created**: `architecture/` 四份（三個 API 面向、金流商子系統、遊戲商子系統、Olympus 客服後台）；`apis/` 與 `data-model/` 由 `php artisan knowledge:scaffold` 從路由、命令與資料庫 schema 產骨架，各區 `index.md` 同命令產生。

## 2026-10-16

* **Initialization**: 建立知識庫，首批自 Outline 遷入遊戲商 callback 共用檢查、SpadegamingSW 與 MotivationSW 單一錢包回調三頁（原路徑 `features/05-99.md`、`features/05-99-SG.md`、`features/05-99-MS.md`，2026-10-21 已改寫成 `rules/` 的文件）。
