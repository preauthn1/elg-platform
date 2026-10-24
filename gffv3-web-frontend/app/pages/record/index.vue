<script setup lang="ts">
import { firstVisibleRecordType } from '~/utils/records'

definePageMeta({ requiresAuth: true })

const { tabFlags } = useRecords()

await navigateTo(`/record/${firstVisibleRecordType(tabFlags.value)}`, { replace: true })
</script>

<template>
  <p class="text-sm text-muted">
    {{ $t('common.ui.label.loading') }}
  </p>
</template>
