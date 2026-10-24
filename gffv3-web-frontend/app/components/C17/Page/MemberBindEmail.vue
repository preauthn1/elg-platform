<script setup lang="ts">
const { t } = useI18n()
const member = useMember()
const router = useRouter()

onMounted(() => {
  void member.ensureLoaded()
})

/** 照舊版 C13 `Page/BindEmail.vue`：成功彈「成功」並回上一頁。 */
function onDone() {
  useAlert().show(t('common.ui.msg.success'), 'success')
  router.back()
}
</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('member.bindEmail.title.bindEmail')"
      fallback="/member/setting"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <div class="rounded-(--c17-radius-sm) bg-surface p-(--c17-gap-3) shadow-[var(--c17-shadow-panel)]">
        <MemberBindChannelForm
          channel="email"
          @done="onDone"
        />
      </div>
    </div>
  </div>
</template>
