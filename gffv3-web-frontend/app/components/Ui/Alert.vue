<script setup lang="ts">
/**
 * 全站提示彈窗。掛在外殼上，由 useAlert() 觸發，頁面不用各自宣告。
 * 訊息內容一律是後端給的（`res.message`），不自己改寫。
 */
const { state, close, confirm } = useAlert()
</script>

<template>
  <UiModal
    v-if="state"
    :title="$t('common.ui.title.prompt')"
    @close="close"
  >
    <div class="flex flex-col gap-(--c17-gap-4)">
      <p
        class="whitespace-pre-line"
        :style="{
          fontSize: 'var(--c17-text-body)',
          color: state.tone === 'success' ? 'var(--c17-success)' : 'var(--c17-error)',
        }"
      >
        {{ state.message }}
      </p>
      <p
        v-if="state.tid"
        class="break-all"
        :style="{ fontSize: 'var(--c17-text-link)', color: 'var(--c17-muted)' }"
      >
        {{ $t('common.ui.label.errorCode', { tid: state.tid }) }}
      </p>
      <div
        v-if="state.confirm"
        class="flex gap-(--c17-gap-3)"
      >
        <UiButton
          variant="secondary"
          @click="close"
        >
          {{ $t('common.ui.action.close') }}
        </UiButton>
        <UiButton @click="confirm">
          {{ $t(state.confirm.label) }}
        </UiButton>
      </div>
      <UiButton
        v-else
        @click="close"
      >
        {{ $t('common.ui.action.confirm') }}
      </UiButton>
    </div>
  </UiModal>
</template>
