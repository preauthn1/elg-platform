<script setup lang="ts">
/** C17 指定存款方式。預選與進頁的活動檢查在 useDepositEntry。 */
const router = useRouter()
const { preselect, checked, blocked } = useDepositEntry()
const showSkeleton = useSkeleton(() => !checked.value)
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('deposit.entry.title.deposit')"
      fallback="/topup"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) pb-(--c17-gap-4) pt-(--c17-gap-4)">
      <NuxtLink
        to="/topup"
        class="self-end text-accent"
        :style="{ fontSize: 'var(--c17-text-link)' }"
      >
        {{ $t('deposit.common.title.deposit') }}
      </NuxtLink>

      <UiSkeleton
        v-if="showSkeleton"
        :rows="6"
      />
      <DepositOnlinePayment
        v-else-if="checked && !blocked"
        :preselect-order-type-id="preselect"
        @return="navigateTo('/topup')"
      />
    </div>

    <DepositMessageBox
      v-if="blocked"
      :message="$t('deposit.entry.error.eventBlocked')"
      @close="router.back()"
    />
  </div>
</template>
