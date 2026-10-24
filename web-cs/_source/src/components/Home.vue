<template>
  <div id="content" class>
    <!-- <BlockUI :message="msg" v-show="spinnerStatus" style="z-index:99999">
      <i class="fa fa-cog fa-spin fa-3x fa-fw"></i>
    </BlockUI>-->
    <div class="titleBg titleHeaderBg">
      <div class="titleContent friendtalkTitle clearfix">
        <!--  -->
        <div
          class="float-left friendtalkMenu selectAction"
          :class="$route.name=='Main' ? 'selectBtn':''"
        >
          <a
            href="/"
            onclick="ga('send', 'event', 'HomeMenu', 'FriendListBtn_Click', 'FriendList');"
          >
            <div class="friendtalkFont titleFontColor">{{ $t('friend') }}</div>
          </a>
        </div>
        <div
          class="float-left friendtalkMenu selectAction"
          :class="$route.name=='TalkList' ? 'selectBtn':''"
        >
          <a
            href="/TalkList"
            onclick="ga('send', 'event', 'HomeMenu', 'TalkListBtn_Click', 'TalkList');"
          >
            <div class="friendtalkFont titleFontColor">{{ $t('chat') }}</div>
          </a>
        </div>
        <div class="float-right menuBtn">
          <img src="../assets/img/menuBtn.png" style="visibility:hidden" />
          <Slide width="200" Position="right" style="background-color:yellow;">
            <a
              href="/AddFriend"
              onclick="ga('send', 'event', 'HomeMenu', 'AddFriendBtn_Click', 'AddFriend');"
              class="addFriend"
            >
              <div class="slideFont">
                <span>{{ $t("addFriends") }}</span>
              </div>
            </a>
            <!-- <div class="slideFont">
              <span>發起群聊</span>
            </div>-->
            <a
              href="/BlackList"
              onclick="ga('send', 'event', 'HomeMenu', 'BlackListBtn_Click', 'BlackList');"
            >
              <div class="slideFont">
                <span>{{ $t("blackList") }}</span>
              </div>
            </a>
            <a href="/SetId" onclick="ga('send', 'event', 'HomeMenu', SetIdBtn_Click', 'SetId');">
              <div class="slideFont">
                <span>{{ $t("myId") }}</span>
              </div>
            </a>
          </Slide>
        </div>
      </div>
    </div>

    <router-view></router-view>
    <footer></footer>
  </div>
</template>
<script>
//ios back reload
window.addEventListener('pageshow', function(e) {
  var isIOS = navigator.userAgent.indexOf('iPhone') > -1
  if (isIOS && localStorage.getItem('isNeedRefresh')) {
    localStorage.removeItem('isNeedRefresh')
    window.location.reload()
  }
})

// import '@/assets/scss/style.css'
import '../assets/scss/style.css'
import { Slide } from 'vue-burger-menu'
// import axios from 'axios'
export default {
  components: {
    Slide
  },
  data() {
    return {
      memberData: '',
      msg: 'Loading...',
      spinnerStatus: false,
      EventMode: sessionStorage.getItem('EventMode')
    }
  },
  mounted() {
    var template = sessionStorage.getItem('Template')
    // console.log(template, 'app.vue')
    if (this.EventMode == 1) {
      $('.addFriend').css('display', 'none')
    }
    if (template == 'C711') {
      $('#content').removeClass()
      $('#content').addClass('redTemp')
      // 紅色
      //   console.log('red')
      var bm_menu = {
        background: '-webkit-linear-gradient(#230700, #4c110e, #731c1b)',
        background: '-o-linear-gradient(#230700, #4c110e, #731c1b)',
        background: '-moz-linear-gradient(#230700, #4c110e, #731c1b)',
        background: 'linear-gradient(#230700, #4c110e, #731c1b)'
      }
      $('.bm-menu').css(bm_menu)
    } else {
      $('#content').removeClass()
      $('#content').addClass('blueTemp')
      // 藍色
      //   console.log('blue')
      var bm_menu = {
        background: '-webkit-linear-gradient(#000b14, #002547, #102c74)',
        background: '-o-linear-gradient(#000b14, #002547, #102c74)',
        background: '-moz-linear-gradient(#000b14, #002547, #102c74)',
        background: 'linear-gradient(#000b14, #002547, #102c74)'
      }
      $('.bm-menu').css(bm_menu)
    }
  }
  //   created() {
  //     let $vue = this
  //     axios.interceptors.request.use(
  //       function(config) {
  //         $vue.spinnerStatus = true
  //         return config
  //       },
  //       function(error) {
  //         $vue.spinnerStatus = false
  //         return Promise.reject(error)
  //       }
  //     )
  //     // 使用setTimeout是為了demo效果，不然資料撈太快，會沒看到loading效果
  //     // 因此故意延遲3秒後才隱藏loading視窗
  //     axios.interceptors.response.use(
  //       function(response) {
  //         setTimeout(function() {
  //           $vue.spinnerStatus = false
  //         }, 3000)
  //         return response
  //       },
  //       function(error) {
  //         $vue.spinnerStatus = false
  //         return Promise.reject(error)
  //       }
  //     )
  //   }
}
</script>
<style scoped>
</style>

