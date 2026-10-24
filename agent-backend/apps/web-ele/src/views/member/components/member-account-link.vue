<script lang="ts" setup>
import CopyIcon from '#/components/copy-icon.vue';

import {
  ensureMemberDrawerAccess,
  memberDrawerAllowed,
  openMemberDetail,
} from './member-detail-drawer-state';

/**
 * 會員帳號可點共用元件（4274）：點擊開啟會員詳情抽屜。
 *
 * 只用於「會員帳號」欄位——代理帳號、操作人員欄不可套用（工單 A-T8）。
 * member_id／username 擇一即可點（username 由 view 接口解析）；
 * label 供顯示文字與帳號不同的場景（如聊天表頭顯示暱稱）。
 * 4342：帳號右側常駐複製 icon，複製的是 username 而非 label。
 * 4438 第 7 項：只有系統管理者、總代理（含子帳號）、客服能用抽屜；其他身份顯示純文字、不能點，複製 icon 保留。
 */
const props = defineProps<{
  label?: null | string;
  memberId?: null | number | string;
  username?: null | string;
}>();

ensureMemberDrawerAccess();

function onClick() {
  openMemberDetail({ memberId: props.memberId, username: props.username });
}
</script>

<template>
  <span v-if="memberId || username">
    <span
      v-if="memberDrawerAllowed"
      class="text-primary cursor-pointer font-medium hover:underline"
      @click.stop="onClick"
    >
      {{ label || username }}
    </span>
    <span v-else>{{ label || username }}</span>
    <CopyIcon :text="username" />
  </span>
  <span v-else>{{ label || username || '-' }}</span>
</template>
