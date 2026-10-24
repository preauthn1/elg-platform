<script setup lang="ts">
import type { VipContent } from '#shared/api/endpoints/vip'
import {
  describeKeepAmount,
  describeRebateProportion,
  describeTargetAmount,
  findRewardPoint,
  isNoneValue,
  type DisplayValue,
} from '#shared/utils/vip'

/**
 * 某一個等級的權益清單。設計稿 VIP 與 VIP_All-Benefits_pop out 列的是固定七項，
 * 順序、標題與取值都寫死在下面的 ROWS，**不跟著後端的 reward[] 順序跑**——
 * 這樣每一級列出來的欄位才會一樣，缺的那幾項顯示鎖頭而不是整列消失。
 *
 * 版型量到的值：
 *   圖示 26×26 圓角 4、名稱與值都是 PJS SemiBold 12 #1a1f36（值靠右）
 *   列與列之間 1px 白線，最後一條是 1px 螢光 #d8ff1a
 *   該等級沒有的項目不寫「無」，右邊放 20×20 的灰色圓形鎖頭（設計稿的 Layer_2）
 *
 * 獎勵列用 `vip_rewards.id` 對應，不是靠 reward[] 的順序。**對照表與舊專案
 * `_source2/src/mixins/Vip.vue` 的 `bonusData` 逐欄相同**（該處註解已寫明欄名）：
 *
 *   getRewardValue(level, 1) 季度等級獎金 → Quarterly Bonus
 *   getRewardValue(level, 4) 升級獎金     → Promotion Bonus
 *   getRewardValue(level, 3) 紀念獎金     → Honor Bonus
 *   getRebateProportion(level)            → Exclusive Rebate（現場 / 老虎機）
 *   getRescueBonus(level, 8, max)         → Weekly Care Bonus（比例 / 上限）
 *
 * id 的意義另可對照 apis 的 `app/Enums/LivechatBotVipRewardEnum.php`。
 * 晉級門檻、保級標準與專屬返水不是 reward，各自有欄位。
 *
 * **「上限」目前拿不到**：`vip/contents` 的 RewardResource 只回 id／name／point，
 * 資料表的 `max_point` 沒有送出來。舊專案是在皮裡逐級寫死（`getRescueBonus(3, 8, '1,000')`
 * 這種），而且每個品牌一份——那正是這個專案不帶進來的東西，所以先只顯示比例。
 */
const props = withDefaults(defineProps<{
  content: VipContent | null
  /**
   * 是否自己帶頁面的左右內距。VIP 頁是 fullBleed 版面所以要帶；
   * 放進彈窗時要關掉，不然會和彈窗自己的內距疊成兩層。
   */
  padded?: boolean
  /**
   * 是否列出晉級門檻。VIP 頁把它當第一列；等級說明彈窗把它畫成徽章下面的置中標題，
   * 那邊自己渲染，所以要關掉避免出現兩次。
   */
  showUpgrade?: boolean
}>(), { padded: true, showUpgrade: true })

const { t } = useI18n()

/** `public/skins/c17/vip/` 底下的檔名，與設計稿由上到下的七顆圖示對應。 */
type Source = 'target' | 'keep' | 'rebate' | number

interface RowDef {
  key: string
  icon: string
  labelKey: string
  /** 數字從哪裡來：三個固定欄位，或某個 vip_rewards.id */
  from: Source
}

const ROWS: readonly RowDef[] = [
  { key: 'upgrade', icon: 'upgrade', labelKey: 'vip.condition.label.upgradeRequirement', from: 'target' },
  { key: 'retention', icon: 'retention', labelKey: 'vip.condition.label.retentionRequirement', from: 'keep' },
  { key: 'quarterly', icon: 'quarterly', labelKey: 'vip.privilege.label.quarterlyBonus', from: 1 },
  { key: 'promotion', icon: 'promotion', labelKey: 'vip.privilege.label.promotionBonus', from: 4 },
  { key: 'honor', icon: 'honor', labelKey: 'vip.privilege.label.honorBonus', from: 3 },
  { key: 'rebate', icon: 'exclusive-rebate', labelKey: 'vip.privilege.label.exclusiveRebate', from: 'rebate' },
  { key: 'weekly-care', icon: 'weekly-care', labelKey: 'vip.privilege.label.weeklyCareBonus', from: 8 },
]

function valueOf(content: VipContent, from: Source): DisplayValue {
  if (from === 'target') return describeTargetAmount(content)
  if (from === 'keep') return describeKeepAmount(content)
  if (from === 'rebate') return describeRebateProportion(content)
  return findRewardPoint(content, from)
}

/**
 * 兩列的標題要帶括號說明，與舊專案 bonusData 那張表的欄名一致：
 *   專屬返水    （現場遊戲 / 老虎機）——兩個遊戲類型的比例
 *   週度關懷金  （比例 / 上限）——後端只給得出比例，上限見元件說明
 */
function labelOf(row: RowDef): string {
  if (row.from === 'rebate') {
    return `${t(row.labelKey)} (${t('games.enum.category.reality')} / ${t('games.enum.category.slot')})`
  }
  if (row.key === 'weekly-care') {
    return `${t(row.labelKey)} (${t('agent.commission.label.ratio')} / ${t('common.amount.unit.upperLimit')})`
  }
  return t(row.labelKey)
}

const rows = computed(() => {
  const c = props.content
  if (!c) return []
  return ROWS
    .filter(row => props.showUpgrade || row.from !== 'target')
    .map((row) => {
      const value = valueOf(c, row.from)
      return {
        key: row.key,
        icon: row.icon,
        name: labelOf(row),
        // 晉級門檻多一行「存款 + 1 倍流水」，與 VIP 頁既有的寫法一致
        note: row.from === 'target' ? t('vip.condition.title.depositTurnover', { value: 1 }) : '',
        value: isNoneValue(value) ? undefined : ('i18n' in value ? t(value.i18n) : value.text),
      }
    })
})
</script>

<template>
  <ul
    v-if="rows.length > 0"
    :style="{ paddingInline: padded ? 'var(--c17-pad-x)' : undefined }"
  >
    <li
      v-for="(row, index) in rows"
      :key="row.key"
      class="flex items-center justify-between gap-[var(--c17-gap-3)] py-[var(--c17-gap-2)]"
      :style="{
        borderBottom: `1px solid ${index === rows.length - 1 ? 'var(--c17-accent)' : 'var(--c17-surface)'}`,
        fontSize: 'var(--c17-text-link)',
      }"
    >
      <!-- 設計稿每列最左邊是 26×26、圓角 4 的圖示 -->
      <img
        :src="`/skins/c17/vip/${row.icon}.webp`"
        alt=""
        class="size-[26px] shrink-0 rounded-[var(--c17-radius-xs)] object-contain"
      >
      <span class="min-w-0 flex-1 font-semibold text-ink">{{ row.name }}</span>

      <span
        v-if="row.value !== undefined"
        class="shrink-0 text-right font-semibold text-ink"
      >
        {{ row.value }}
        <span
          v-if="row.note"
          class="block"
        >({{ row.note }})</span>
      </span>

      <!-- 這個等級沒有這項：設計稿放 20×20 的灰色圓形鎖頭 -->
      <img
        v-else
        src="/skins/c17/vip/lock.webp"
        class="size-5 shrink-0"
        :alt="$t('common.ui.label.none')"
      >
    </li>
  </ul>
</template>
