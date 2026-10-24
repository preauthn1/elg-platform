# 架構

<!-- knowledge:generated start -->
## 金流

* [architecture/payment-providers 金流商子系統](payment-providers.md) - 金流商 Provider 的目錄要有哪些檔、怎麼註冊在 config/payment.php，以及代收代付回調從哪裡進來。

## 遊戲與遊戲商

* [architecture/game-vendors 遊戲商子系統](game-vendors.md) - 轉帳錢包與無縫錢包兩種遊戲商各自繼承哪個基底類別、怎麼註冊在 config/vendor.php、無縫錢包回調的前置檢查怎麼分工，以及拉注單、注單彙總、帳號池、遊戲清單同步與退回未結算注單這幾個維運元件的觸發方式與先後關係。

## Olympus

* [architecture/olympus-admin Olympus 客服後台](olympus-admin.md) - APIS 內建的 Filament v5 後台（/olympus）怎麼部署、怎麼登入，以及系統操作頁怎麼執行。

## 系統

* [architecture/api-facets 三個 API 面向](api-facets.md) - Frontend、Agent、External 三個 API 面向各自用哪些路由檔、怎麼驗證身分、Controller 放在哪，以及 IP 白名單在哪一層擋。
<!-- knowledge:generated end -->
