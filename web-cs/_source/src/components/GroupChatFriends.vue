<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="search clearfix">
      <div class="float-left searchinput">
        <input class="form-control inputbg" v-model="searchInput" id="Search" placeholder="搜尋" />
      </div>
      <div class="float-right searchBtn">
        <i class="fa fa-lg fa-search searchicon" @click="addFriendBtn()"></i>
      </div>
    </div>
    <div class="blackList allContent">
      <div class="groupcheckBtn float-left">
        <b-form-checkbox
          id="checkbox-1"
          v-model="status"
          name="checkbox-1"
          value="accepted"
          unchecked-value="not_accepted"
        />
      </div>
      <div class="blackGroup clearfix" @click="checkBlock">
        <div class="blackPic float-left">
          <img src="../assets/img/pic2.jpg" />
        </div>
        <div class="blackFont ContentFont float-left">王小強</div>
      </div>
      <div class="groupcheckBtn float-left">
        <input type="checkbox" aria-label="Checkbox for following text input" />
      </div>
      <div class="blackGroup clearfix" @click="checkBlock">
        <div class="blackPic float-left">
          <img src="../assets/img/pic1.jpg" />
        </div>
        <div class="blackFont ContentFont float-left">周大強</div>
      </div>
      <div class="groupcheckBtn float-left">
        <input type="checkbox" aria-label="Checkbox for following text input" />
      </div>
      <div class="blackGroup clearfix" @click="checkBlock">
        <div class="blackPic float-left">
          <img src="../assets/img/game5.png" />
        </div>
        <div class="blackFont ContentFont float-left">吳中強</div>
      </div>
      <div class="groupcheckBtn float-left">
        <input type="checkbox" aria-label="Checkbox for following text input" />
      </div>
      <div class="blackGroup clearfix" @click="checkBlock">
        <div class="blackPic float-left">
          <img src="../assets/img/game1.png" />
        </div>
        <div class="blackFont ContentFont float-left">謝大胖</div>
      </div>
      <div class="groupcheckBtn float-left">
        <input type="checkbox" aria-label="Checkbox for following text input" />
      </div>
      <div class="blackGroup clearfix" @click="checkBlock">
        <div class="blackPic float-left">
          <img src="../assets/img/pic2.jpg" />
        </div>
        <div class="blackFont ContentFont float-left">王小強</div>
      </div>
      <div class="groupcheckBtn float-left">
        <input type="checkbox" aria-label="Checkbox for following text input" />
      </div>
      <div class="blackGroup clearfix" @click="checkBlock">
        <div class="blackPic float-left">
          <img src="../assets/img/pic1.jpg" />
        </div>
        <div class="blackFont ContentFont float-left">周大強</div>
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
  methods: {
    // checkBlock: function() {
    //   this.isShow = !this.isShow
    //   if (this.isShow) {
    //
    //     $('.checkBtn').show()
    //   } else {
    //
    //     $('.checkBtn').hide()
    //   }
    // }
    // checkBlock() {
    // }
  }
}
</script>
<style scoped>
</style>