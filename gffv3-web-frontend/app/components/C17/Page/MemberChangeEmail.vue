<script setup lang="ts">
const member = useMember()
const flow = useTemplateRef<{ back: () => boolean }>('flow')

onMounted(() => {
  void member.ensureLoaded()
})
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.bindEmail.title.changeEmail')"
      fallback="/member/setting"
      :balance="false"
      :step-back="() => flow?.back() ?? false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <MemberChangeBindingFlow
          ref="flow"
          channel="email"
        />
      </div>
    </div>
  </div>
</template>
