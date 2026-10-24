/**
 * 自訂的 definePageMeta 欄位。
 *
 * fullBleed：關掉外殼的左右留白，給已照設計稿刻好、內部自己控制留白的頁面用
 * （首頁的輪播與遊戲橫列要切齊螢幕邊緣，彈窗類頁面也自己排）。
 * 這是「同一個版面的一個開關」，不是第二個 layout——分成兩個 layout 會讓
 * Nuxt 在切頁時把整個外殼卸載重建，頂部列每次重掛就重打一次餘額。
 *
 * hideAppHeader：不要掛帶餘額的漸層頂部列。設計稿裡 Home v3、Promo、VIP 最上面
 * 都是可關閉的下載列、沒有餘額列（首頁的餘額載體是頁面中段那條黃綠 WalletBar），
 * 只有 Game-page 畫了餘額列。標在頁面上而不是在外殼裡列一張路徑表，
 * 這樣新增頁面時看得到這個選項。
 */
declare module '#app' {
  interface PageMeta {
    fullBleed?: boolean
    hideAppHeader?: boolean
  }
}

declare module 'vue-router' {
  interface RouteMeta {
    fullBleed?: boolean
    hideAppHeader?: boolean
  }
}

export {}
