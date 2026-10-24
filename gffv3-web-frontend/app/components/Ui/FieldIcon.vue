<script setup lang="ts">
/** 輸入框的前置／後置圖示，22×22。素材在 public/skins/c17/field/。 */
defineProps<{
  /** public/skins/c17/field/<name>.webp */
  name: string
  /** 無障礙用的說明；純裝飾就不要給 */
  alt?: string
}>()
</script>

<template>
  <!--
    **不要加任何陰影。**

    向量資料上每顆圖示都寫著 `shadow 3,3 blur 2 #1a1f36/26%`，但**畫板的算圖沒有渲染它**：
    取樣過 form-components 的算圖，圖示右下方緊鄰的像素是純白 rgb(255,255,255)
    ——那正是 3,3 位移的陰影最深的位置。前置的藍色方塊與眼睛都一樣，兩者都沒有影子。

    照那行字加陰影兩種寫法都是錯的：box-shadow 會讓透明線稿的眼睛旁邊浮出一塊矩形，
    drop-shadow 雖然跟著線條走、但設計根本沒有這層。
    **向量資料寫了陰影，不代表算圖看得到；以算圖為準。**
  -->
  <img
    :src="`/skins/c17/field/${name}.webp`"
    :alt="alt ?? ''"
    class="size-[22px] shrink-0 object-contain"
  >
</template>
