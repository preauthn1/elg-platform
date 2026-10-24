<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="sgroupName clearfix">
      <div class="sgroupBpic float-left">
        <img src="../assets/img/pic2.jpg" />
        <img src="../assets/img/sCamera.png" class="sCamera" />
      </div>
      <div class="sgroupBname ContentFont float-left">群組名稱</div>
    </div>
    <div class="memberLine ContentFont">成員</div>
    <div class="addMemberAll clearfix">
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/addMember.png" class="imgBorder" />
        </div>
        <div class="memberName ContentFont">新增</div>
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/pic2.jpg" />
        </div>
        <div class="memberName ContentFont">Yeee</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/pic1.jpg" />
        </div>
        <div class="memberName ContentFont">阿巴巴</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/game1.png" />
        </div>
        <div class="memberName ContentFont">哈瞜你好嗎</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/game2.png" />
        </div>
        <div class="memberName ContentFont">我是誰</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/pic1.jpg" />
        </div>
        <div class="memberName ContentFont">阿巴巴</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/game1.png" />
        </div>
        <div class="memberName ContentFont">哈瞜你好嗎</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
      </div>
      <div class="memberItem float-left">
        <div class="memberPic">
          <img src="../assets/img/game2.png" />
        </div>
        <div class="memberName ContentFont">我是誰</div>
        <img src="../assets/img/redCloseBtn.png" class="redCloseBtn" />
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

