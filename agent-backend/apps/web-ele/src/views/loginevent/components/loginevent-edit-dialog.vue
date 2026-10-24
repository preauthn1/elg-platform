<script lang="ts" setup>
import { computed, reactive, ref } from 'vue';

import dayjs from 'dayjs';
import {
  ElButton,
  ElCard,
  ElDatePicker,
  ElDialog,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElMessage,
  ElOption,
  ElRadio,
  ElRadioGroup,
  ElSelect,
  ElTabPane,
  ElTabs,
} from 'element-plus';

import {
  getLanguagesApi,
  getLoginEventViewApi,
  getParamListApi,
  getVendorListApi,
  saveLoginEventApi,
} from '#/api';
import { tl } from '#/locales';

const emit = defineEmits<{ success: [] }>();

const visible = ref(false);
const eventId = ref<number | string>('');
const isEdit = computed(() => Boolean(eventId.value));

const languages = ref<Array<{ id: number | string; name: string }>>([]);
const types = ref<Array<{ parameter?: string; value: number | string }>>([]);
const vendors = ref<Array<{ id: number | string; name: string }>>([]);
const submitting = ref(false);
const activeLang = ref('default');
let dropdownsLoaded = false;

const form = reactive(makeDefaults());

function makeDefaults() {
  return {
    content: '',
    contents: {} as Record<string, string>,
    end_time: dayjs().add(1, 'month').format('YYYY-MM-DD 23:59'),
    how_days: 0,
    number_of_people: 0,
    point: 0,
    repeat_hour: 0,
    start_time: dayjs().format('YYYY-MM-DD 00:00'),
    status: 1 as number | string,
    times: 0,
    title: '',
    titles: {} as Record<string, string>,
    type: '' as number | string,
    vendor_id: [] as Array<number | string>,
  };
}

async function handleSave() {
  if (!form.title.trim()) {
    ElMessage.warning(tl('event_name'));
    return;
  }
  if (!form.type) {
    ElMessage.warning(tl('choose_event_category'));
    return;
  }
  submitting.value = true;
  try {
    const res = await saveLoginEventApi({
      ...(isEdit.value ? { id: eventId.value } : {}),
      content: form.content,
      contents: form.contents,
      end_time: form.end_time,
      how_days: form.how_days,
      number_of_people: form.number_of_people,
      point: form.point,
      repeat_hour: form.repeat_hour,
      start_time: form.start_time,
      status: form.status,
      times: form.times,
      title: form.title,
      titles: form.titles,
      type: form.type,
      vendor_id: form.vendor_id.join(','),
    });
    if (Number(res.code) === 1000) {
      ElMessage.success(tl('success'));
      visible.value = false;
      emit('success');
    } else {
      ElMessage.error(res.message || tl('failure'));
    }
  } finally {
    submitting.value = false;
  }
}

async function open(id: number | string = '') {
  eventId.value = id || '';
  Object.assign(form, makeDefaults());
  activeLang.value = 'default';
  visible.value = true;
  if (!dropdownsLoaded) {
    const [langs, typeList, vendorList] = await Promise.all([
      getLanguagesApi().catch(() => []),
      getParamListApi('login_event_type').catch(() => []),
      getVendorListApi().catch(() => []),
    ]);
    languages.value = (langs as any) ?? [];
    types.value = (typeList as any) ?? [];
    vendors.value = vendorList ?? [];
    dropdownsLoaded = true;
  }
  if (isEdit.value) {
    const res = await getLoginEventViewApi(eventId.value);
    if (Number(res.code) === 1000 && res.data) {
      const e = res.data as any;
      form.title = e.title ?? '';
      form.content = e.content ?? '';
      form.type = e.type ?? '';
      form.start_time = e.start_time ?? form.start_time;
      form.end_time = e.end_time ?? form.end_time;
      form.how_days = Number(e.how_days ?? 0);
      form.point = Number(e.point ?? 0);
      form.times = Number(e.times ?? 0);
      form.repeat_hour = Number(e.repeat_hour ?? 0);
      form.number_of_people = Number(e.number_of_people ?? 0);
      form.status = Number(e.status) === 1 ? 1 : 0;
      const vendorIds = new Set(vendors.value.map((v) => Number(v.id)));
      form.vendor_id = e.vendor_id
        ? String(e.vendor_id)
            .split(',')
            .map((s: string) => Number(s.trim()))
            .filter((id) => vendorIds.has(id))
        : [];
      for (const n of e.names ?? []) {
        form.titles[String(n.language_id)] = n.title ?? '';
        form.contents[String(n.language_id)] = n.content ?? '';
      }
    }
  }
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('event_login_management')} - ${isEdit ? tl('edit') : tl('add')}`"
    top="5vh"
    width="960"
  >
    <div class="max-h-[74vh] overflow-y-auto pr-1">
      <ElCard class="mb-3" shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('event_and_content') }}</span>
        </template>
        <ElTabs v-model="activeLang">
          <ElTabPane :label="tl('default')" name="default">
            <ElForm label-position="top">
              <ElFormItem>
                <template #label>
                  <span class="text-red-500">*</span> {{ tl('event_name') }}
                </template>
                <ElInput v-model="form.title" />
              </ElFormItem>
              <ElFormItem>
                <template #label>
                  <span class="text-red-500">*</span> {{ tl('event_content') }}
                </template>
                <ElInput v-model="form.content" :rows="3" type="textarea" />
              </ElFormItem>
            </ElForm>
          </ElTabPane>
          <ElTabPane
            v-for="lang in languages"
            :key="lang.id"
            :label="lang.name"
            :name="`lang-${lang.id}`"
          >
            <ElForm label-position="top">
              <ElFormItem :label="tl('event_name')">
                <ElInput v-model="form.titles[String(lang.id)]" />
              </ElFormItem>
              <ElFormItem :label="tl('event_content')">
                <ElInput
                  v-model="form.contents[String(lang.id)]"
                  :rows="3"
                  type="textarea"
                />
              </ElFormItem>
            </ElForm>
          </ElTabPane>
        </ElTabs>
      </ElCard>

      <ElCard shadow="never">
        <template #header>
          <span class="font-medium">{{ tl('event_condition_setting') }}</span>
        </template>
        <ElForm label-position="top">
          <div class="grid grid-cols-1 gap-x-6 md:grid-cols-3">
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span>
                {{ tl('choose_event_category') }}
              </template>
              <ElSelect
                v-model="form.type"
                :placeholder="tl('please_choose')"
                class="!w-full"
              >
                <ElOption
                  v-for="t in types"
                  :key="t.value"
                  :label="tl(`login_event_type.${t.value}`)"
                  :value="t.value"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span> {{ tl('label_start_time') }}
              </template>
              <ElDatePicker
                v-model="form.start_time"
                class="!w-full"
                format="YYYY-MM-DD HH:mm"
                type="datetime"
                value-format="YYYY-MM-DD HH:mm"
              />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span> {{ tl('label_end_time') }}
              </template>
              <ElDatePicker
                v-model="form.end_time"
                class="!w-full"
                format="YYYY-MM-DD HH:mm"
                type="datetime"
                value-format="YYYY-MM-DD HH:mm"
              />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span>
                {{ tl('not_withdraw_record') }}
              </template>
              <ElInputNumber v-model="form.how_days" :min="0" class="!w-full" />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span> {{ tl('trial_amount') }}
              </template>
              <ElInputNumber v-model="form.point" :min="0" class="!w-full" />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span> {{ tl('unlock_amount') }}
              </template>
              <ElInputNumber v-model="form.times" :min="0" class="!w-full" />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span>
                {{ tl('label_number_of_participants') }}
              </template>
              <ElInputNumber
                v-model="form.repeat_hour"
                :min="0"
                class="!w-full"
              />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span>
                {{ tl('label_the_number_of_participants') }}
              </template>
              <ElInputNumber
                v-model="form.number_of_people"
                :min="0"
                class="!w-full"
              />
            </ElFormItem>
            <ElFormItem>
              <template #label>
                <span class="text-red-500">*</span> {{ tl('status') }}
              </template>
              <ElRadioGroup v-model="form.status">
                <ElRadio :value="1">{{ tl('enable') }}</ElRadio>
                <ElRadio :value="0">{{ tl('disable') }}</ElRadio>
              </ElRadioGroup>
            </ElFormItem>
          </div>
          <ElFormItem>
            <template #label>
              <span class="text-red-500">*</span> {{ tl('trial_vendor') }}
            </template>
            <ElSelect
              v-model="form.vendor_id"
              class="!w-full"
              collapse-tags
              collapse-tags-tooltip
              filterable
              multiple
            >
              <ElOption
                v-for="v in vendors"
                :key="v.id"
                :label="v.name"
                :value="v.id"
              />
            </ElSelect>
          </ElFormItem>
        </ElForm>
      </ElCard>
    </div>

    <template #footer>
      <ElButton @click="visible = false">{{ tl('btn_back') }}</ElButton>
      <ElButton :loading="submitting" type="primary" @click="handleSave">
        {{ tl('btn_update') }}
      </ElButton>
    </template>
  </ElDialog>
</template>
