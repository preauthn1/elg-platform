<template>
  <div>
    <div class="titleBg titleHeaderBg">
      <div class="titleContent friendtalkTitle clearfix">
        <!--領取-->
        <div
          class="float-left friendtalkMenu selectAction"
          :class="$route.name=='RedReceive' ? 'selectBtn':''"
        >
          <a href="/RedReceive">
            <div class="friendtalkFont titleFontColor">{{$t('receiveRed')}}</div>
          </a>
        </div>
        <!--發送-->
        <div
          class="float-left friendtalkMenu selectAction"
          :class="$route.name=='RedSend' ? 'selectBtn':''"
        >
          <a href="/RedSend">
            <div class="friendtalkFont titleFontColor">{{$t('sendRed')}}</div>
          </a>
        </div>
        <a
          :href="chatCode == 'undefined'?'/TalkById/' + this.friendId:'/TalkByCode/' + this.chatCode"
        >
          <div class="float-right">
            <img src="../assets/img/redClose.png" />
          </div>
        </a>
      </div>
    </div>

    <router-view></router-view>
    <footer></footer>
  </div>
</template>
<script>
export default {
  components: {},
  data() {
    return {
      chatCode: ''
    }
  },
  mounted() {
    this.chatCode = sessionStorage.getItem('chatCode')
    this.friendId = sessionStorage.getItem('friendId')
  }
}
</script>
<style scoped>
</style>

