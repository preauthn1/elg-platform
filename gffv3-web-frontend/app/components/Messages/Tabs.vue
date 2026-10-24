<script setup lang="ts">
const { template } = useSite()
const route = useRoute()
const messages = useMessagesStore()

/** unread：該分頁的未讀數，> 0 時標紅點；進分頁時會全部已讀並歸零。 */
const tabs = computed(() => {
  const list: { to: string, key: string, unread?: number }[] = [
    { to: '/messages', key: 'messages.common.nav.latestNews', unread: messages.platformAnnouncementUnread },
    { to: '/messages/system', key: 'messages.system.nav.notification', unread: messages.systemNotificationUnread },
  ]
  if (template.value?.process === 'P2') list.push({ to: '/messages/events', key: 'events.list.title.news' })
  list.push({ to: '/messages/mail', key: 'messages.common.nav.inbox', unread: messages.memberMailUnread })
  return list
})
</script>

<template>
  <nav class="-mx-(--c17-pad-x) flex gap-(--c17-gap-2) overflow-x-auto px-(--c17-pad-x) pt-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    <NuxtLink
      v-for="tab in tabs"
      :key="tab.to"
      :to="tab.to"
      class="relative flex h-8 shrink-0 items-center rounded-(--c17-field-radius) border px-(--c17-gap-3) font-bold"
      :style="{
        fontSize: 'var(--c17-text-link)',
        background: route.path === tab.to ? 'var(--c17-primary)' : 'var(--c17-surface)',
        color: route.path === tab.to ? 'var(--c17-primary-ink)' : 'var(--c17-ink)',
        borderColor: route.path === tab.to ? 'var(--c17-primary)' : 'var(--c17-faint)',
      }"
    >
      {{ $t(tab.key) }}
      <span
        v-if="(tab.unread ?? 0) > 0"
        class="absolute -right-1 -top-1 size-2 rounded-full"
        :style="{ background: 'var(--c17-badge)' }"
      />
    </NuxtLink>
  </nav>
</template>
