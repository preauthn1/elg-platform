<script setup lang="ts">
/** C17 的畫面，邏輯在 useMessagesMailPage。 */
const {
  page,
  items,
  pages,
  loading,
  loadFailed,
  selected,
  open,
} = useMessagesMailPage()
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('messages.common.nav.inbox')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <MessagesTabs />

      <p
        v-if="loading"
        class="text-sm text-muted"
      >
        {{ $t('common.ui.label.loading') }}
      </p>
      <p
        v-else-if="loadFailed || items.length === 0"
        class="text-sm text-muted"
      >
        {{ $t('messages.inbox.empty.noMessage') }}
      </p>

      <ul
        v-else
        class="space-y-2"
      >
        <li
          v-for="item in items"
          :key="item.id"
        >
          <button
            type="button"
            class="block w-full rounded-(--c17-radius-sm) bg-surface shadow-[var(--c17-shadow-panel)] p-3 text-left"
            @click="open(item)"
          >
            <div class="flex items-center justify-between text-sm">
              <span class="font-semibold">
                {{ $t('messages.common.nav.inbox') }}
                <span
                  v-if="item.is_read === 0"
                  class="ml-1 inline-block h-2 w-2 rounded-full bg-danger align-middle"
                />
              </span>
              <span class="text-(length:--c17-text-label) text-muted">{{ item.publish_time }}</span>
            </div>
            <div class="mt-1 font-medium">
              {{ item.title }}
            </div>
            <div class="truncate text-sm text-muted">
              {{ item.content }}
            </div>
          </button>
        </li>
      </ul>

      <UiPagination
        v-model:page="page"
        :pages="pages"
      />

      <div
        v-if="selected"
        class="fixed inset-0 z-50 flex items-center justify-center bg-scrim p-4"
        @click.self="selected = null"
      >
        <div class="w-full max-w-md rounded bg-surface p-4">
          <h2 class="font-semibold">
            {{ selected.title }}
          </h2>
          <p class="mt-1 text-(length:--c17-text-label) text-muted">
            {{ selected.publish_time }}
          </p>
          <p class="mt-2 whitespace-pre-wrap text-sm">
            {{ selected.content }}
          </p>
          <div class="mt-4 text-right">
            <button
              type="button"
              class="rounded bg-accent px-4 py-1 text-sm text-inverse"
              @click="selected = null"
            >
              {{ $t('common.ui.action.close') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
