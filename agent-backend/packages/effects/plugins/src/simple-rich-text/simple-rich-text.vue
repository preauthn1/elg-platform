<script lang="ts" setup>
import { computed, onBeforeUnmount, watch } from 'vue';

import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  Bold,
  Italic,
  List,
  ListOrdered,
  Strikethrough,
  Underline as UnderlineIcon,
} from '@vben/icons';
import { $t } from '@vben/locales';

import TextAlign from '@tiptap/extension-text-align';
import Underline from '@tiptap/extension-underline';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';

/**
 * 精簡文字編輯器：段落樣式、清單、粗斜刪底線、對齊。
 *
 * 不用同套件的 VbenTiptap——那支工具列寫死（另含圖片上傳、文字顏色、螢光筆、
 * 引用、程式碼區塊等），toolbar prop 只有 boolean 無法篩選，也沒 expose
 * editor 供外部控制。營運只需要基本排版，多餘按鈕反而增加誤用與內容失控的
 * 風險——尤其圖片上傳會把 base64 塞進設定欄位。
 *
 * 放在 @vben/plugins 而非 apps/web-ele：@tiptap 只裝在本套件的 node_modules，
 * app 端要用得先加相依，而 lockfile 的 supply-chain policy 目前擋著 install。
 */
defineOptions({ name: 'SimpleRichText' });

const props = withDefaults(
  defineProps<{
    /** 可否編輯 */
    editable?: boolean;
    /** 內容區最小高度 */
    minHeight?: number;
  }>(),
  { editable: true, minHeight: 180 },
);

const model = defineModel<string>({ default: '' });

const editor = useEditor({
  content: model.value ?? '',
  editable: props.editable,
  extensions: [
    // 關掉工具列沒有的節點類型，避免從外部貼上時帶進無法編輯的內容
    StarterKit.configure({
      blockquote: false,
      code: false,
      codeBlock: false,
      heading: { levels: [1, 2, 3] },
      horizontalRule: false,
      link: false,
    }),
    Underline,
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
  ],
  onUpdate: ({ editor: e }) => {
    const html = e.getHTML();
    // 空內容時 Tiptap 會回 <p></p>，統一成空字串免得存進無意義的標籤
    model.value = html === '<p></p>' ? '' : html;
  },
});

// 外部改值（載入、取消編輯還原）時同步進編輯器；比對避免打斷使用者輸入
watch(model, (val) => {
  const current = editor.value?.getHTML();
  const next = val || '';
  if (editor.value && current !== next && !(current === '<p></p>' && !next)) {
    editor.value.commands.setContent(next, { emitUpdate: false });
  }
});

watch(
  () => props.editable,
  (val) => editor.value?.setEditable(val),
);

onBeforeUnmount(() => editor.value?.destroy());

/** 段落樣式下拉：內文與三階標題 */
const headingOptions = [
  {
    is: () => editor.value?.isActive('paragraph'),
    label: $t('ui.tiptap.toolbar.paragraph'),
    run: () => editor.value?.chain().focus().setParagraph().run(),
    short: 'P',
  },
  ...([1, 2, 3] as const).map((level) => ({
    is: () => editor.value?.isActive('heading', { level }),
    label: `${$t('ui.tiptap.toolbar.heading')} ${level}`,
    run: () => editor.value?.chain().focus().toggleHeading({ level }).run(),
    short: `H${level}`,
  })),
];

/** 目前段落樣式，顯示在下拉觸發鈕上 */
const headingLabel = computed(
  () => headingOptions.find((o) => o.is())?.short ?? 'P',
);

/** 工具列按鈕：分組排列，組間以分隔線區隔 */
const actionGroups = [
  [
    {
      icon: List,
      is: () => editor.value?.isActive('bulletList'),
      key: 'bulletList',
      run: () => editor.value?.chain().focus().toggleBulletList().run(),
      title: $t('ui.tiptap.toolbar.bulletList'),
    },
    {
      icon: ListOrdered,
      is: () => editor.value?.isActive('orderedList'),
      key: 'orderedList',
      run: () => editor.value?.chain().focus().toggleOrderedList().run(),
      title: $t('ui.tiptap.toolbar.orderedList'),
    },
  ],
  [
    {
      icon: Bold,
      is: () => editor.value?.isActive('bold'),
      key: 'bold',
      run: () => editor.value?.chain().focus().toggleBold().run(),
      title: $t('ui.tiptap.toolbar.bold'),
    },
    {
      icon: Italic,
      is: () => editor.value?.isActive('italic'),
      key: 'italic',
      run: () => editor.value?.chain().focus().toggleItalic().run(),
      title: $t('ui.tiptap.toolbar.italic'),
    },
    {
      icon: Strikethrough,
      is: () => editor.value?.isActive('strike'),
      key: 'strike',
      run: () => editor.value?.chain().focus().toggleStrike().run(),
      title: $t('ui.tiptap.toolbar.strike'),
    },
    {
      icon: UnderlineIcon,
      is: () => editor.value?.isActive('underline'),
      key: 'underline',
      run: () => editor.value?.chain().focus().toggleUnderline().run(),
      title: $t('ui.tiptap.toolbar.underline'),
    },
  ],
  [
    {
      icon: AlignLeft,
      is: () => editor.value?.isActive({ textAlign: 'left' }),
      key: 'left',
      run: () => editor.value?.chain().focus().setTextAlign('left').run(),
      title: $t('ui.tiptap.toolbar.alignLeft'),
    },
    {
      icon: AlignCenter,
      is: () => editor.value?.isActive({ textAlign: 'center' }),
      key: 'center',
      run: () => editor.value?.chain().focus().setTextAlign('center').run(),
      title: $t('ui.tiptap.toolbar.alignCenter'),
    },
    {
      icon: AlignRight,
      is: () => editor.value?.isActive({ textAlign: 'right' }),
      key: 'right',
      run: () => editor.value?.chain().focus().setTextAlign('right').run(),
      title: $t('ui.tiptap.toolbar.alignRight'),
    },
  ],
];
</script>

<template>
  <div class="simple-rich-text border-border w-full rounded border">
    <div
      v-if="editable"
      class="border-border flex items-center gap-1 border-b p-1"
    >
      <!-- 段落樣式：原生 select，免多帶一個 popover 元件 -->
      <select
        :value="headingLabel"
        class="hover:bg-accent/50 h-7 rounded bg-transparent px-1 text-xs outline-none transition-colors"
        :title="$t('ui.tiptap.toolbar.heading')"
        @change="
          headingOptions
            .find((o) => o.short === ($event.target as HTMLSelectElement).value)
            ?.run()
        "
      >
        <option v-for="o in headingOptions" :key="o.short" :value="o.short">
          {{ o.short }}
        </option>
      </select>

      <template v-for="(group, gi) in actionGroups" :key="gi">
        <span class="bg-border mx-1 h-4 w-px"></span>

        <button
          v-for="a in group"
          :key="a.key"
          :class="
            a.is() ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50'
          "
          :title="a.title"
          class="flex size-7 items-center justify-center rounded transition-colors"
          type="button"
          @click="a.run()"
        >
          <component :is="a.icon" class="size-4" />
        </button>
      </template>
    </div>

    <EditorContent
      :editor="editor"
      :style="{ minHeight: `${minHeight}px` }"
      class="simple-rich-text__content px-3 py-2"
    />
  </div>
</template>

<style scoped>
.simple-rich-text__content :deep(.ProseMirror) {
  min-height: inherit;
  outline: none;
}

.simple-rich-text__content :deep(.ProseMirror p) {
  margin: 0 0 0.5em;
}

/* scoped 樣式進不去 ProseMirror 產生的節點，標題與清單需自行給樣式 */
.simple-rich-text__content :deep(.ProseMirror h1) {
  margin: 0.5em 0;
  font-size: 1.5em;
  font-weight: 600;
}

.simple-rich-text__content :deep(.ProseMirror h2) {
  margin: 0.5em 0;
  font-size: 1.25em;
  font-weight: 600;
}

.simple-rich-text__content :deep(.ProseMirror h3) {
  margin: 0.5em 0;
  font-size: 1.1em;
  font-weight: 600;
}

.simple-rich-text__content :deep(.ProseMirror ul),
.simple-rich-text__content :deep(.ProseMirror ol) {
  padding-left: 1.5em;
  margin: 0 0 0.5em;
}

.simple-rich-text__content :deep(.ProseMirror ul) {
  list-style: disc;
}

.simple-rich-text__content :deep(.ProseMirror ol) {
  list-style: decimal;
}
</style>
