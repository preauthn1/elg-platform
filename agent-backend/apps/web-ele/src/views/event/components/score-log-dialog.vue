<script lang="ts" setup>
import type { ScoreLogRow, ScoreRecordRow } from '#/api';

import { ref } from 'vue';

import {
  ElButton,
  ElDialog,
  ElInput,
  ElOption,
  ElSelect,
  ElTable,
  ElTableColumn,
} from 'element-plus';

import { getScoreRecordLogsApi } from '#/api';
import DateRangeFields from '#/components/date-range-fields.vue';
import TablePagination from '#/components/table-pagination.vue';
import { useListQuery } from '#/composables/use-list-query';
import { tl } from '#/locales';
import { amountThousands } from '#/utils/format';
import { fromApiTime, toApiTime } from '#/utils/timezone-shift';

/**
 * 積分紀錄 › 查看（target/tickets#4232）：單一會員的積分異動明細，
 * 期間可用快速鈕（今日／昨日／本週／上週／本月／上月）或自訂；預設不限期間。
 */
defineOptions({ name: 'ScoreLogDialog' });

const PER_PAGE = 20;

/** 明細型別，對應 apis ScoreLogTypeEnum */
const LOG_TYPE = { CONSUME: 2, EXPIRE: 4, MANUAL: 3, REWARD: 1 } as const;
/** 方向，對應 apis ScoreLogDirectionEnum */
const DIRECTION_INCREASE = 1;
/** 參照型別，對應 apis ScoreSourceTypeEnum::EVENT_V2_USER_JOIN */
const REFERENCE_EVENT_V2 = 'event_v2_user_join';

/**
 * 來源篩選選項：值編成 `type` 或 `type-direction`（人工調整要靠 direction 分發放／扣除），
 * 與 sourceText() 的組法對稱；'' ＝ 全部。
 */
const SOURCE_OPTIONS = [
  {
    label: 'score_source_manual_grant',
    value: `${LOG_TYPE.MANUAL}-${DIRECTION_INCREASE}`,
  },
  { label: 'score_source_manual_deduct', value: `${LOG_TYPE.MANUAL}-2` },
  { label: 'score_source_expire', value: `${LOG_TYPE.EXPIRE}` },
  { label: 'score_source_event_reward', value: `${LOG_TYPE.REWARD}` },
  { label: 'score_source_event_consume', value: `${LOG_TYPE.CONSUME}` },
] as const;

const visible = ref(false);
const member = ref<null | ScoreRecordRow>(null);
const timeRange = ref<string[]>(['', '']);
const batchId = ref('');
const source = ref('');

function sourceFilter(): { direction?: number; type?: number } {
  if (!source.value) return {};
  const [type, direction] = source.value.split('-').map(Number);
  return { direction: direction || undefined, type };
}

const { handlePageChange, list, loading, page, search, total } =
  useListQuery<ScoreLogRow>(
    ({ page, per_page }) => {
      const [start, end] = timeRange.value;
      const batch = Number(batchId.value.trim());
      return getScoreRecordLogsApi({
        ...sourceFilter(),
        batch_id: batch > 0 ? batch : undefined,
        end_time: end ? toApiTime(`${end}:59`) : undefined,
        member_id: member.value?.member_id ?? 0,
        page,
        per_page,
        start_time: start ? toApiTime(`${start}:00`) : undefined,
      });
    },
    { perPage: PER_PAGE },
  );

function open(row: ScoreRecordRow) {
  member.value = row;
  timeRange.value = ['', ''];
  batchId.value = '';
  source.value = '';
  visible.value = true;
  search();
}

/** 來源文案由型別 × 參照組出；活動來源在後面附活動名稱 */
function sourceText(row: ScoreLogRow): string {
  const isEvent = row.reference_type === REFERENCE_EVENT_V2;
  const activity = row.activity_name ? ` · ${row.activity_name}` : '';
  switch (Number(row.type)) {
    case LOG_TYPE.CONSUME: {
      return (
        (isEvent
          ? tl('score_source_event_consume')
          : tl('score_source_consume')) + activity
      );
    }
    case LOG_TYPE.EXPIRE: {
      return tl('score_source_expire');
    }
    case LOG_TYPE.MANUAL: {
      return Number(row.direction) === DIRECTION_INCREASE
        ? tl('score_source_manual_grant')
        : tl('score_source_manual_deduct');
    }
    case LOG_TYPE.REWARD: {
      return (
        (isEvent
          ? tl('score_source_event_reward')
          : tl('score_source_reward')) + activity
      );
    }
    default: {
      return String(row.type);
    }
  }
}

const isIncrease = (row: ScoreLogRow) =>
  Number(row.direction) === DIRECTION_INCREASE;

function changeText(row: ScoreLogRow): string {
  return `${isIncrease(row) ? '+' : '-'}${amountThousands(row.score, 2)}`;
}

/** 到期日：發放類無到期＝永不過期；扣除／到期類本就沒有到期日，留空 */
function expireText(row: ScoreLogRow): string {
  if (row.expired_at) return fromApiTime(row.expired_at);
  return isIncrease(row) ? tl('score_never_expires') : '-';
}

defineExpose({ open });
</script>

<template>
  <ElDialog
    v-model="visible"
    :title="`${tl('member_score')} - ${member?.username ?? ''}`"
    width="1000"
    align-center
  >
    <div class="mb-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
      <span>
        {{ tl('available_score') }}：
        <b>{{ amountThousands(member?.available_score ?? 0, 2) }}</b>
      </span>
      <span>
        {{ tl('expired_score') }}：
        {{ amountThousands(member?.expired_score ?? 0, 2) }}
      </span>
      <span>
        {{ tl('granted_score') }}：
        {{ amountThousands(member?.granted_score ?? 0, 2) }}
      </span>
    </div>

    <div class="mb-3 flex flex-wrap items-start gap-2">
      <div class="min-w-0 flex-1">
        <DateRangeFields
          v-model="timeRange"
          format="YYYY-MM-DD HH:mm"
          type="datetime"
          value-format="YYYY-MM-DD HH:mm"
        />
      </div>
      <ElInput
        v-model="batchId"
        :placeholder="tl('score_batch_id')"
        class="!w-32"
        clearable
        @keyup.enter="search"
      />
      <ElSelect v-model="source" class="!w-40" @change="search">
        <ElOption :label="`${tl('source')}：${tl('all')}`" value="" />
        <ElOption
          v-for="opt in SOURCE_OPTIONS"
          :key="opt.value"
          :label="tl(opt.label)"
          :value="opt.value"
        />
      </ElSelect>
      <ElButton :loading="loading" type="primary" @click="search">
        {{ tl('search') }}
      </ElButton>
    </div>

    <ElTable v-loading="loading" :data="list" border size="small" stripe>
      <ElTableColumn :label="tl('time')" width="170">
        <template #default="{ row }">
          {{ fromApiTime(row.changed_at) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('score_batch_id')" prop="batch_id" width="90" />
      <ElTableColumn :label="tl('source')" min-width="200">
        <template #default="{ row }">
          {{ sourceText(row as ScoreLogRow) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('score_change')" align="right" width="120">
        <template #default="{ row }">
          <span
            :class="
              isIncrease(row as ScoreLogRow) ? 'text-green-600' : 'text-red-500'
            "
          >
            {{ changeText(row as ScoreLogRow) }}
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('after_score')" align="right" width="120">
        <template #default="{ row }">
          {{ amountThousands(row.after_score, 2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('score_expire_date')" width="170">
        <template #default="{ row }">
          {{ expireText(row as ScoreLogRow) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('operator')" width="120">
        <template #default="{ row }">
          {{ row.operator || tl('system') }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="tl('memo')" min-width="160">
        <template #default="{ row }">
          <span class="whitespace-pre-line break-words">
            {{ row.remark || '-' }}
          </span>
        </template>
      </ElTableColumn>
      <template #empty>{{ tl('no_record') }}</template>
    </ElTable>
    <TablePagination
      :current-page="page"
      :page-size="PER_PAGE"
      :total="total"
      layout="total, prev, pager, next"
      @current-change="handlePageChange"
    />
  </ElDialog>
</template>
