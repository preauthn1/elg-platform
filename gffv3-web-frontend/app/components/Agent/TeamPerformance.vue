<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'
import type { AchievementPeriodType } from '#shared/utils/agent'

/**
 * 設計稿 Invite-Friends 的「PERFORMANCE QUERY」：member-team/achievement，昨日／前天切換即重抓。
 *
 * 分段切換是**一顆 358×32 的圓角槽**（底 #F1F4F8、1pt #B4B4B4），
 * 選中的那半是 178×32 的實心藍（#1565FF）、字 #F1F4F8，未選中的字是 #7A8599。
 * 結算界線由後端算（14:00），前端只負責標籤。
 */
const { achievement, achievementPeriod, periods, fetchAchievement } = useMemberTeam()
const { t } = useI18n()

const loading = ref(true)
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(loading)

const columns = computed(() => [
  t('games.common.label.category'),
  t('agent.performance.label.total'),
  t('agent.performance.label.direct'),
  t('agent.performance.title.performance'),
])

const rows = computed(() => (achievement.value?.data ?? []).map(row => [
  row.name,
  decFormat(row.totalAchievement),
  decFormat(row.directPlayersAchievement),
  decFormat(row.teamAchievement),
]))

const footer = computed(() => {
  const total = achievement.value?.total
  if (!total) return null
  return [
    t('common.pagination.label.total'),
    decFormat(total.totalAchievement),
    decFormat(total.directPlayersAchievement),
    decFormat(total.teamAchievement),
  ]
})

async function load(type: AchievementPeriodType) {
  loading.value = true
  try {
    await fetchAchievement(type)
  }
  finally {
    loading.value = false
  }
}

onMounted(() => load(1))
</script>

<template>
  <section class="flex flex-col gap-(--c17-gap-2)">
    <h2
      class="font-extrabold uppercase"
      :style="{
        fontFamily: 'var(--c17-font-display)',
        fontSize: 'var(--c17-text-title)',
        color: 'var(--c17-ink)',
      }"
    >
      {{ $t('agent.performance.nav.performanceQuery') }}
    </h2>

    <div class="flex h-8 overflow-hidden rounded-(--c17-radius-sm) border border-field-line bg-surface-2">
      <button
        v-for="period in periods"
        :key="period.type"
        type="button"
        class="flex-1 font-bold"
        :style="{
          fontSize: 'var(--c17-text-link)',
          background: achievementPeriod === period.type ? 'var(--c17-primary)' : 'transparent',
          color: achievementPeriod === period.type ? 'var(--c17-primary-ink)' : 'var(--c17-muted)',
        }"
        @click="load(period.type)"
      >
        {{ $t(period.labelKey) }}
      </button>
    </div>

    <UiTable
      :columns="columns"
      :rows="rows"
      :footer="footer"
      :loading="showSkeleton"
    />
  </section>
</template>
