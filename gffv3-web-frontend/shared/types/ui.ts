/**
 * 純呈現用的型別（不是 API 契約）。
 * API 回應的形狀在 `shared/api/endpoints/`，這裡只放「元件要什麼」。
 */

/**
 * 首頁／遊戲大廳那條橫幅輪播的一張。
 *
 * 刻意**不**收 `AdvertiseItem`：來源不只一種——首頁是 advertise 的廣告，
 * 遊戲大廳是寫死在皮裡的類型橫幅。讓元件只認這個最小形狀，兩邊都不必假裝成對方
 * （硬湊一個假的 AdvertiseItem 就是在編資料）。
 */
export interface HeroSlide {
  /** v-for 的 key，同一組裡唯一即可 */
  key: string | number
  /** 圖片網址；空字串代表這張沒圖，呼叫端自己先濾掉 */
  image: string
  /** 圖片替代文字；橫幅的字通常燒在圖裡，沒有就留空 */
  alt?: string
  /** 點下去要做什麼。沒給就是不可點，游標與 tabindex 會跟著變 */
  onSelect?: () => void | Promise<void>
}
