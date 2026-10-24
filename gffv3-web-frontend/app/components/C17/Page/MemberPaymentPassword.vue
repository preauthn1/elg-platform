<script setup lang="ts">
const member = useMember()
const form = useTemplateRef<{ back: () => boolean }>('form')

onMounted(() => {
  void member.ensureLoaded()
})
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.paymentPassword.label.paymentPassword')"
      fallback="/member/setting"
      :balance="false"
      :step-back="() => form?.back() ?? false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <MemberPaymentPasswordForm
          ref="form"
          :require-current="false"
        />
      </div>
    </div>
  </div>
</template>
