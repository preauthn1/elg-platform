import type { HeroSlide } from '#shared/types/ui'

/**
 * 遊戲類型橫幅（設計稿 Game-page 最上面那張 390×140 的 `game type banner`）。
 *
 * 素材是**寫死在皮裡**的，不走 advertise：`BANNER_PATH.GAME_TYPE_INDEX`（2）在舊前台
 * 只有定義、沒有任何地方用，後端也回空陣列。檔案在
 * `public/skins/c17/lobby-banner/`，一律 780×280（@2x）。
 *
 * 廠商大廳（/vendors）與廠商底下的遊戲列表（/game/list/<vendorId>）共用這一份，
 * 不要各自再寫一次路徑與判斷。
 */

/**
 * 有素材的類型 → 檔名。副檔名要寫進來，因為老虎機那張是設計稿匯出的 PNG，
 * 站方後來補的五張是 JPG；靠 typeId 拼副檔名會少一邊。
 * 類型代碼見 `shared/utils/games.ts` 的 `GAME_TYPE_I18N_KEY`。
 */
const BANNER_FILE: Record<number, string> = {
  1: '1.webp', // 真人
  2: '2.webp', // 老虎機
  4: '4.webp', // 彩票
  5: '5.webp', // 捕魚
  15: '15.webp', // 街機
  18: '18.webp', // 體育
}

export function lobbyBannerImage(typeId: number): string {
  return `/skins/c17/lobby-banner/${BANNER_FILE[typeId]}`
}

export function hasLobbyBanner(typeId: number | null): boolean {
  return typeId !== null && typeId in BANNER_FILE
}

/**
 * **只有選了類型才放橫幅**，而且只放那個類型自己那張。
 * ALL GAMES 不放——橫幅是某個類型的宣傳圖（烤著「SLOT / PLAY NOW」），
 * 掛在「全部遊戲」上等於說全部遊戲都是老虎機。
 */
export function lobbyBannerSlides(typeId: number | null): HeroSlide[] {
  if (!hasLobbyBanner(typeId)) return []
  return [{ key: typeId as number, image: lobbyBannerImage(typeId as number) }]
}
