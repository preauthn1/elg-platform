import type { LegacyEventPreset } from '~/utils/events-core'

/** /events/[preset] 的邏輯，所有皮共用，畫面在 components/<皮>/Page/EventsPreset.vue。 */
export function useEventsPresetPage() {
  const route = useRoute()
  const { t } = useI18n()

  const preset = computed(() => route.params.preset as LegacyEventPreset)

  /**
   * V-／W- 開頭的是產品名，舊皮的 Events.vue `title()` 也是直接寫死，不進語言包。
   * 另外兩個舊皮沒給標題（fall through 成字面值 "events"），這裡補上既有的 key。
   *
   * 型別鎖成 `Record<LegacyEventPreset, …>`：往 LEGACY_EVENT_PRESETS 加值卻忘了補標題，
   * typecheck 會紅。
   */
  const TITLES: Record<LegacyEventPreset, () => string> = {
    'v-rebate': () => 'V-Rebate',
    'w-rebate': () => 'W-Rebate',
    'v-jackpot': () => 'V-Jackpot',
    'w-jackpot': () => 'W-Jackpot',
    'w-jackpot6d': () => 'W-Jackpot6d',
    'lose-rebate': () => t('records.enum.transactionType.lossRebate'),
    'wtt-sports': () => t('games.enum.category.sports'),
  }

  const title = computed(() => TITLES[preset.value]())

  return {
    preset,
    title,
  }
}
