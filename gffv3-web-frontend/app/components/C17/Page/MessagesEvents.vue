<script setup lang="ts">

</script>

<template>
  <div>
    <C17PageHeader
      :title="$t('messages.common.title.notification')"
      fallback="/member"
      :balance="false"
    />

    <div class="flex flex-col gap-(--c17-gap-3) px-(--c17-pad-x) py-(--c17-gap-4)">
      <MessagesTabs />
      <MessagesPlatformAnnouncementList :tag-filter="3" />
    </div>
  </div>
</template>
