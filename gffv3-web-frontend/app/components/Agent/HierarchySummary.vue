<script setup lang="ts">
import { buildTgShareUrl } from '#shared/utils/agent'

/**
 * 代理總覽（unlimit-hierarchy / three-hierarchy）：帳號、人數、分享連結與推廣碼。
 * 資料由 useAgentCenter().fetchSummary() 放進 store，這裡只綁定。
 */
const props = defineProps<{
  /** TG 分享文字（tg_share_text），沒有就只帶連結 */
  tgShareText?: string
}>()

const { summary, shareLinks, isTgUser } = useAgentCenter()
const copied = ref('')

async function copy(text: string, tag: string) {
  if (!text || !import.meta.client) return
  try {
    await navigator.clipboard.writeText(text)
    copied.value = tag
  }
  catch {
    copied.value = ''
  }
}

const tgShareUrl = computed(() => (
  shareLinks.value ? buildTgShareUrl(shareLinks.value.copyLink, props.tgShareText ?? '') : ''
))

const cells = computed(() => {
  const s = summary.value
  if (!s) return []
  return [
    { key: 'agent.team.label.superiorAccount', value: s.topUsername, show: true },
    { key: 'agent.center.label.account', value: s.username, show: true },
    { key: 'agent.team.label.teamMember', value: s.teamCount, show: s.mode === 'unlimit' },
    { key: 'agent.team.label.directMember', value: s.directPlayerCount, show: s.mode === 'unlimit' },
    { key: 'agent.team.label.downlineMember', value: s.directPlayerCount, show: s.mode === 'three' },
    { key: 'agent.team.label.level3Member', value: s.doubleDirectPlayerCount, show: s.mode === 'three' },
    { key: 'agent.team.label.newToday', value: s.addedToday, show: true },
    { key: 'agent.team.label.activeToday', value: s.activeToday, show: true },
  ].filter(c => c.show)
})
</script>

<template>
  <section
    v-if="summary"
    class="space-y-4 rounded border border-line bg-surface p-4"
  >
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
      <template
        v-for="cell in cells"
        :key="cell.key"
      >
        <dt class="text-muted">
          {{ $t(cell.key) }}
        </dt>
        <dd class="font-semibold">
          {{ cell.value }}
        </dd>
      </template>
    </dl>

    <div
      v-if="shareLinks"
      class="space-y-2 text-sm"
    >
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-muted">{{ $t('agent.promote.label.promoteLink') }}</span>
        <code class="break-all">{{ shareLinks.copyLink }}</code>
        <button
          type="button"
          class="rounded border border-line px-2 py-0.5 text-xs"
          @click="copy(shareLinks.copyLink, 'link')"
        >
          {{ copied === 'link' ? $t('common.ui.msg.copied') : $t('common.ui.action.copy') }}
        </button>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-muted">{{ $t('agent.promote.label.promoteCode') }}</span>
        <code>{{ summary.code }}</code>
        <button
          type="button"
          class="rounded border border-line px-2 py-0.5 text-xs"
          @click="copy(summary.code, 'code')"
        >
          {{ copied === 'code' ? $t('common.ui.msg.copied') : $t('common.ui.action.copy') }}
        </button>
      </div>
      <div class="flex flex-wrap gap-3">
        <NuxtLink
          to="/agent/qrcode"
          class="text-accent underline"
        >
          {{ $t('agent.promote.label.qrCode') }}
        </NuxtLink>
        <a
          v-if="isTgUser"
          :href="tgShareUrl"
          class="text-accent underline"
        >
          {{ $t('agent.promote.action.shareTelegram') }}
        </a>
      </div>
    </div>
  </section>
</template>
