<script setup lang="ts">
import { decFormat } from '#shared/utils/amount'

/**
 * 設計稿 Invite-Friends 的「TEAM MANAGEMENT」：member-team/team-management。
 * 輸入框 358×32、圓角 8、白底 1pt #B4B4B4，左邊一顆 22×22 的 Player ID 圖示。
 * 查不到（非直屬或不存在）後端回 code 200 + []，視為查無。
 */
const { teamMember, searchTeamMember } = useMemberTeam()
const { t } = useI18n()

const keyword = ref('')

/** 這張表一開始本來就是空的（還沒查詢），所以不是 true 起手 */
const loading = ref(false)
/** 骨架的出現時機由 useSkeleton 決定，不可以直接綁 loading（見該 composable 的說明） */
const showSkeleton = useSkeleton(loading)

const columns = computed(() => [t('agent.team.label.memberId'), t('agent.performance.label.direct')])
const rows = computed(() => (
  teamMember.value ? [[teamMember.value.username, decFormat(teamMember.value.achievements)]] : []
))

async function search() {
  loading.value = true
  try {
    await searchTeamMember(keyword.value.trim())
  }
  finally {
    loading.value = false
  }
}
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
      {{ $t('agent.team.title.teamManagement') }}
    </h2>

    <form
      class="flex h-8 items-center gap-(--c17-gap-2) rounded-(--c17-radius-sm) border border-field-line bg-surface px-(--c17-gap-2)"
      @submit.prevent="search"
    >
      <img
        src="/skins/c17/invite/player-id.webp"
        alt=""
        class="size-[22px] shrink-0 rounded-(--c17-radius-xs) object-contain"
      >
      <input
        v-model="keyword"
        type="text"
        class="min-w-0 flex-1 bg-transparent font-semibold placeholder:text-placeholder focus:outline-none"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-ink)' }"
        :placeholder="$t('agent.team.placeholder.memberId')"
      >
      <button
        type="submit"
        class="shrink-0 font-bold"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-primary)' }"
      >
        {{ $t('common.ui.action.search') }}
      </button>
    </form>

    <!-- 設計稿這張表一直在（還沒查詢時是空的），不是查完才出現 -->
    <UiTable
      :columns="columns"
      :rows="rows"
      :loading="showSkeleton"
      :skeleton-rows="1"
    />
  </section>
</template>
