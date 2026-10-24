import type { Ref } from 'vue'
import type { GameListItem, GamesByTagItem } from '#shared/api/endpoints/games'

type PlayableGame = GameListItem | GamesByTagItem

/**
 * 從遊戲卡片進遊戲：VPower 要先跳提示、彈窗被瀏覽器擋要提示使用者。
 * 遊戲列表、標籤、搜尋三頁原本各寫一份，收在這裡給各皮共用。
 */
export function useGamePlay<T extends PlayableGame>() {
  const launcher = useGameLauncher()

  /** 'popup'：開新視窗被擋，畫面顯示提示 */
  const notice = ref('')
  /** 有值時畫面顯示 VPower 的進場提示 */
  const vpowerGame = ref<T | null>(null) as Ref<T | null>

  async function play(game: T) {
    notice.value = ''
    try {
      const entry = await launcher.enterGame(game)
      if (entry.kind === 'vpower-notice') vpowerGame.value = game
    }
    catch {
      notice.value = 'popup'
    }
  }

  return { notice, vpowerGame, play }
}
