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
        <i class="fa fa-lg fa-search searchicon"></i>
      </div>
    </div>
    <div class="searchTalkAll">
      <div class="searchTitle ContentFont">訊息3</div>
      <div class="searchCon">
        <a href="/Talk">
          <div class="talkGroup clearfix">
            <div class="talkPic float-left">
              <img src="../assets/img/game2.png" />
            </div>
            <div class="talkName ContentFont float-left">
              <div class="talkGropName">王大胖</div>
              <div class="talkContent talkListFont">黑~我跟你說</div>
            </div>
            <div class="talkTime ContentFont float-right">19:34</div>
          </div>
        </a>
        <a href="/Talk">
          <div class="talkGroup clearfix">
            <div class="talkPic float-left">
              <img src="../assets/img/game5.png" />
            </div>
            <div class="talkName ContentFont float-left">
              <div class="talkGropName">周小胖</div>
              <div class="talkContent talkListFont">沒有壓</div>
            </div>
            <div class="talkTime ContentFont float-right">20:18</div>
          </div>
        </a>
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
      searchInput: ''
    }
  },
  methods: {}
}
</script>
<style scoped>
</style>