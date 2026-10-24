<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="setNameInput allContent">
      <!-- <div class="setNameNum ContentFont">4/10</div> -->
      <div class="inputBgColor inputBorder inputBorderColor ContentFont inputAll">
        <input type="text" placeholder class="inputTagBg" v-model="nameInput" id="nameInput" />
        <img src="../assets/img/closeBtn.png" @click="resetInput()" />
      </div>
      <div class="buttonAll ContentFont blueBtnPic">
        <span>儲 存</span>
      </div>
    </div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
export default {
  components: {
    listTitle
  },
  data() {
    return {
      nameInput: ''
    }
  },
  methods: {
    resetInput() {
      this.nameInput = ''
    }
  }
}
</script>
<style scoped>
</style>

