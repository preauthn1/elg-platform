<script lang="ts" setup>
import { onMounted, reactive, ref } from 'vue';

import {
  ElButton,
  ElCard,
  ElFormItem,
  ElInput,
  ElOption,
  ElSelect,
} from 'element-plus';

import { getEventV2CategoryListApi, searchAgentOptionsApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import { tl } from '#/locales';
import { nowInUserTz, toApiTime } from '#/utils/timezone-shift';

defineOptions({ name: 'EventV2SearchForm' });

const props = defineProps<{
  /** 是否顯示「活動ID」搜尋欄（僅參加記錄頁需要） */
  showDetailId?: boolean;
  statusOptions: Array<{ label: string; value: number | string }>;
}>();

const emit = defineEmits<{ search: [filters: Record<string, any>] }>();

const filters = reactive({
  agent_username: '',
  event_v2_detail_id: '' as number | string,
  event_v2_id: '' as number | string,
  member_username: '',
  status: '' as number | string,
  time_range: [
    nowInUserTz().format('YYYY-MM-DD 00:00'),
    nowInUserTz().format('YYYY-MM-DD 23:59'),
  ] as string[],
});

const agentOptions = ref<Array<{ id: number; username: string }>>([]);
const agentLoading = ref(false);
const categories = ref<Array<{ id: number; name: string }>>([]);

async function searchAgent(query: string) {
  if (!query) return;
  agentLoading.value = true;
  try {
    const res = await searchAgentOptionsApi(query);
    if (Number(res.code) === 1000) agentOptions.value = res.data ?? [];
  } finally {
    agentLoading.value = false;
  }
}

function doSearch() {
  const [start, end] = filters.time_range ?? [];
  emit('search', {
    agent_username: filters.agent_username,
    end_time: toApiTime(end ?? ''),
    event_v2_detail_id: filters.event_v2_detail_id,
    event_v2_id: filters.event_v2_id,
    member_username: filters.member_username,
    start_time: toApiTime(start ?? ''),
    status: filters.status,
  });
}

onMounted(async () => {
  const res = await getEventV2CategoryListApi();
  if (Number(res.code) === 200) categories.value = res.data ?? [];
  doSearch();
});
</script>

<template>
  <ElCard class="mb-3" shadow="never">
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
      <ElFormItem :label="tl('agent_account')" class="!mb-0">
        <ElSelect
          v-model="filters.agent_username"
          :debounce="500"
          :loading="agentLoading"
          :remote-method="searchAgent"
          class="w-full"
          clearable
          filterable
          remote
        >
          <ElOption
            v-for="a in agentOptions"
            :key="a.id"
            :label="a.username"
            :value="a.username"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem :label="tl('member_account')" class="!mb-0">
        <ElInput v-model="filters.member_username" clearable />
      </ElFormItem>
      <ElFormItem :label="tl('choose_event_category')" class="!mb-0">
        <ElSelect v-model="filters.event_v2_id" class="w-full" clearable>
          <ElOption :label="tl('please_choose')" value="" />
          <ElOption
            v-for="c in categories"
            :key="c.id"
            :label="c.name"
            :value="c.id"
          />
        </ElSelect>
      </ElFormItem>
      <ElFormItem
        v-if="props.showDetailId"
        :label="`${tl('event')}ID`"
        class="!mb-0"
      >
        <ElInput v-model="filters.event_v2_detail_id" clearable />
      </ElFormItem>
      <ElFormItem :label="tl('status')" class="!mb-0">
        <ElSelect v-model="filters.status" class="w-full" clearable>
          <ElOption :label="tl('please_choose')" value="" />
          <ElOption
            v-for="o in props.statusOptions"
            :key="o.value"
            :label="o.label"
            :value="o.value"
          />
        </ElSelect>
      </ElFormItem>
    </div>
    <ElFormItem :label="tl('between')" class="!mb-0 mt-3">
      <DateRangeFields
        v-model="filters.time_range"
        format="YYYY-MM-DD HH:mm"
        type="datetime"
        value-format="YYYY-MM-DD HH:mm"
      />
    </ElFormItem>
    <div class="mt-3 flex justify-end">
      <ElButton type="primary" @click="doSearch">{{ tl('search') }}</ElButton>
    </div>
  </ElCard>
</template>
