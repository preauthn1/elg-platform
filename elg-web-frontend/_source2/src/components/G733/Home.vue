<template>
  <div id="G733" @click="iframeRE()">
    <div class="contentAll">
      <!--活動按鈕點擊引導-->
      <div class="mask" id="fullevents" style="z-index:1000;" v-if="fullevents&&mobilepc=='MOBILE'">
        <div class="Popups_Modal">
          <img src="../../assets/img/G733/rectangle.png" alt class="homeFullEvent" />
          <img
            src="../../assets/img/G733/modalClose.png"
            class="homeFullClose"
            @click="homeFullClose()"
          />

          <div class="homeFullFont">
            <p>{{ $t("youHaveANewEvents") }}</p>
            <span>{{ $t("pleaseClickTheButtonOnTheBottomRight") }}</span>
          </div>
          <div class="homeFullPic">
            <img src="../../assets/img/G733/back-arrow.png" />
          </div>
        </div>
      </div>
      <!-- PC頁面右方聊天按鈕 -->
      <div class="leftFixedBtn" v-if="$route.meta.fixedBtn && loging">
        <div
          v-if="has_chat == 1"
          class="fixedBtnPic"
          data-toggle="modal"
          data-target="#exampleModalCenter1"
          @click="talkIframe()"
        >
          <img src="../../../static/img/C711_PC/fixedBtn2.png" />
        </div>
      </div>
      <!-- 活動浮動按鈕 -->
      <div
        v-if="$route.meta.event"
        class="ys-float-btn"
        :style="{width: itemWidth + 'px',height: itemHeight + 'px',left: left + 'px',top: top + 'px'}"
        ref="myEvents"
        id="myEvents"
        @click="onBtnClicked()"
      >
        <slot name="icon"></slot>
        <div
          class="eventbtn"
          data-toggle="modal"
          data-target="#eventVisible"
          onclick="ga('send', 'event', 'Lobby', 'EventBtn_Click', 'Event');"
        >
          <img src="../../assets/img/G733/mark.gif" class="eventBtnT" v-if="get_EventsReddeem == 1" />

          <span class="eventFont">{{ getEventTotal }}</span>
          <img src="../../assets/img/G733/eventBtn.png" />
        </div>
      </div>

      <!-- EVENTS 彈窗 -->
      <!-- 聊天 -->
      <div
        v-if="loging"
        class="modal right fade"
        id="exampleModalCenter1"
        tabindex="-1"
        role="dialog"
        aria-labelledby="exampleModalCenterTitle"
        aria-hidden="true"
      >
        <div class="modal-dialog modal-dialog-centered" role="document">
          <div class="modal-content">
            <div class="modal-body" style="height:95vh;">
              <iframe
                name="chat"
                width="100%"
                height="100%"
                frameborder="0"
                scrolling="auto"
                id="chat"
                :src="iframeSrc"
                class="iframeChat"
                ref="chat"
              ></iframe>
            </div>
          </div>
        </div>
      </div>
      <!-- 活動彈窗iframe -->
      <div class="modal fade" id="eventVisible" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-dialog-scrollable" role="document">
          <div class="modal-content">
            <div class="colse closeBtn" data-dismiss="modal" aria-label="Close" @click="iframeRE()">
              <img src="../../../static/img/G733/modalClose.png" />
            </div>
            <div class="modal-body">
              <iframe
                id="iframe"
                name="frame"
                src="/Events_i"
                width="102%"
                frameborder="0"
                scrolling="auto"
                class="iframeHeight"
              ></iframe>
            </div>
          </div>
        </div>
      </div>

      <router-view />

      <!-- 手機板FOOTER按鈕 -->
      <footer v-if="$route.meta.footer">
        <div class="G733lobbyMenu">
          <div class="menuBtn">
            <a href="/">
              <div class="btnPosition">
                <div class="MenuBottomBtn">
                  <img src="../../assets/img/G733/lobby4.png" v-if="$route.meta.lobbyBtn" />
                  <div class="LightBtn" v-if="$route.meta.lobbyBtnLight">
                    <div class="LightLine"></div>
                    <img src="../../assets/img/G733/lobby5.png" />
                  </div>
                </div>
                <div class="MenuBottomFont">
                  <span
                    :class="$route.meta.lobbyBtn ? 'fontColorGray': 'fontColorWhite'"
                  >{{ $t("lobby") }}</span>
                </div>
              </div>
            </a>
          </div>
          <div class="menuBtn" v-if="has_chat == 1">
            <a href="/Talk">
              <div class="btnPosition">
                <div class="MenuBottomBtn">
                  <img src="../../assets/img/G733/talk4.png" v-if="$route.meta.talkBtn" />

                  <div class="LightBtn" v-if="$route.meta.talkBtnLight">
                    <div class="LightLine"></div>
                    <img src="../../assets/img/G733/talk5.png" />
                  </div>
                </div>
                <div class="MenuBottomFont">
                  <span
                    :class="$route.meta.talkBtn? 'fontColorGray': 'fontColorWhite'"
                  >{{ $t("talk") }}</span>
                </div>
              </div>
            </a>
          </div>

          <div class="menuBtn" v-if="transaction_all!=1">
            <a href="/Points">
              <div class="btnPosition">
                <div class="MenuBottomBtn">
                  <img src="../../assets/img/G733/wallet4.png" v-if="$route.meta.walletBtn" />

                  <div class="LightBtn" v-if="$route.meta.walletBtnLight">
                    <div class="LightLine"></div>
                    <img src="../../assets/img/G733/wallet5.png" />
                  </div>
                </div>
                <div class="MenuBottomFont">
                  <span
                    :class="$route.meta.walletBtn? 'fontColorGray': 'fontColorWhite'"
                  >{{ $t("wallet") }}</span>
                </div>
              </div>
            </a>
          </div>

          <div class="menuBtn">
            <a href="/Setting">
              <div class="btnPosition">
                <div class="MenuBottomBtn">
                  <img src="../../assets/img/G733/set4.png" v-if="$route.meta.settingBtn" />

                  <div class="LightBtn" v-if="$route.meta.settingBtnLight">
                    <div class="LightLine"></div>
                    <img src="../../assets/img/G733/set5.png" />
                  </div>
                </div>
                <div class="MenuBottomFont">
                  <span
                    :class="$route.meta.settingBtn? 'fontColorGray': 'fontColorWhite'"
                  >{{ $t("set") }}</span>
                </div>
              </div>
            </a>
          </div>
        </div>
      </footer>
      <!-- FOOTER -->
      <div class="footer_block" v-if="$route.meta.footer_block">
        <div class="footer_container">
          <div class="footer_top">
            <router-link to="/QA">
              {{
              $t("commonProblem")
              }}
            </router-link>
            <router-link to="/Rule">
              {{
              $t("termsAndConditions")
              }}
            </router-link>
            <router-link to="/Privacy">
              {{
              $t("privacyPolicy")
              }}
            </router-link>
            <router-link to="/Cookies">
              {{
              $t("cookiesPolicy")
              }}
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import Events from '@/components/C711_Mobile/Events'
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
import { runInDebugContext } from 'vm'
import { setTimeout } from 'timers'
import Bus from './Bus.js'
import '../../assets/scss/G733/style.css'
import maskModal from './Modal'

import Global from '@/Global'
import { isNull } from 'util'
const CryptoJS = require('crypto-js')
import secret from '@/utils/secret'
//ios back reload
window.addEventListener('pageshow', function(e) {
  var isIOS = navigator.userAgent.indexOf('iPhone') > -1
  if (isIOS && localStorage.getItem('isNeedRefresh')) {
    localStorage.removeItem('isNeedRefresh')
    window.location.reload()
  }
})

export default {
  name: 'FloatImgBtn',
  components: {
    Events,
    maskModal
  },
  computed: {
    ...mapGetters([
      'get_EventsTotal',
      'get_Events',
      'get_EventsReddeem',
      'get_EventsOpen',
      'get_getLoginEvent'
    ]),
    // 有登入活動時 TOTAL+1
    getEventTotal() {
      let eventTotal = this.get_getLoginEvent
        ? this.get_EventsTotal + 1
        : this.get_EventsTotal
      return eventTotal
    }
  },
  data() {
    return {
      timer: null,
      currentTop: 0,
      clientWidth: 0,
      clientHeight: 0,
      left: 0,
      top: 0,
      eventVisible: false,
      f: false,
      has_chat: sessionStorage.getItem('has_chat'),
      has_mobile: sessionStorage.getItem('has_mobile'),
      has_pc: sessionStorage.getItem('has_pc'),
      PC_MOBILE: sessionStorage.getItem('PC_MOBILE'),
      transaction_all: sessionStorage.getItem('transaction_all'),
      event_Total: '',
      fullevents: false,
      mobilepc: sessionStorage.getItem('PC_MOBILE'),
      loging: sessionStorage.getItem('token'),
      iframeSrc: Global.$API_CHATPATH
    }
  },
  props: {
    text: {
      type: String,
      default: '預設文字'
    },
    itemWidth: {
      type: Number,
      default: 60
    },
    itemHeight: {
      type: Number,
      default: 60
    },
    gapWidth: {
      type: Number,
      default: 10
    },
    coefficientHeight: {
      type: Number,
      default: 0.8
    }
  },
  created() {
    this.clientWidth = document.documentElement.clientWidth
    this.clientHeight = document.documentElement.clientHeight
    this.left = this.clientWidth - this.itemWidth - this.gapWidth
    example.invalid = this.clientHeight * this.coefficientHeight - 100
    setTimeout(() => {
      if (sessionStorage.getItem('token')) {
        this.$store.dispatch('action_EventList')
      }
    }, 1000)
  },
  mounted() {
    // 取今日日期
    var Today = new Date()
    this.NowDate =
      Today.getFullYear() +
      '-' +
      (Today.getMonth() + 1) +
      '-' +
      Today.getDate() +
      ' ' +
      Today.getHours() +
      ':' +
      Today.getMinutes() +
      ':' +
      Today.getSeconds()

    //接收events.vue 活動總數
    var vm = this

    Bus.$on('total', data => {
      vm.event_Total = data

      sessionStorage.setItem('EventTotal', vm.event_Total)
    })
    setTimeout(() => {
      let locationHref = location.href
      if (
        locationHref.indexOf('Signup') != -1 ||
        locationHref.indexOf('ForgetPassword') != -1 ||
        locationHref.indexOf('RetrievePassword') != -1 ||
        locationHref.indexOf('PhoneVerification') != -1 ||
        locationHref.indexOf('SetPassword') != -1
      ) {
        // 活動彈窗不出現
        this.fullevents = false
      }
    }, 1000)
    setTimeout(() => {
      let eventTotal = sessionStorage.getItem('EventTotal')
      this.event_Total = eventTotal

      let total = $.cookie('EventsTotal')

      let locationHref = location.href

      if (
        locationHref.indexOf('Signup') != -1 ||
        locationHref.indexOf('ForgetPassword') != -1 ||
        locationHref.indexOf('RetrievePassword') != -1 ||
        locationHref.indexOf('PhoneVerification') != -1 ||
        locationHref.indexOf('SetPassword') != -1
      ) {
        this.fullevents = false
      } else {
        if (total == undefined || total == null || eventTotal > total) {
          this.fullevents = true
          // 紀錄活動總數
          $.cookie('EventsTotal', eventTotal)
        }
        // console
      }
    }, 4500)

    if (this.PC_MOBILE == 'MOBILE') {
      if (this.has_mobile == 0 || this.has_mobile == '0') {
        $('#has_pcMask').css('display', 'block')
      }
    }
    //手機方向
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
    window.addEventListener('scroll', this.handleScrollStart)
    this.$nextTick(() => {
      const div = this.$refs.myEvents
      //   addEventListener bug test
      //   const div = document.getElementById('myEvents')
      if (div) {
        div.addEventListener('touchstart', () => {
          div.style.transition = 'none'
        })
        div.addEventListener('touchmove', e => {
          if (e.targetTouches.length === 1) {
            let touch = event.targetTouches[0]
            this.left = touch.clientX - this.itemWidth / 2
            example.invalid = touch.clientY - this.itemHeight / 2
          }
        })
        div.addEventListener('touchend', () => {
          div.style.transition = 'all 0.3s'
          if (this.left > this.clientWidth / 2) {
            this.left = this.clientWidth - this.itemWidth - this.gapWidth
          } else {
            this.left = this.gapWidth
          }
        })
        div.addEventListener(
          'touchmove',
          function cantscroll(e) {
            e.preventDefault()
          },
          { passive: false }
        )
        div.addEventListener('touchend', function cantscroll(e) {
          div.removeEventListener('touchend', cantscroll)
        })
      }
    })
  },
  beforeDestroy() {
    window.removeEventListener('scroll', this.handleScrollStart)
  },
  methods: {
    back() {
      window.history.go(-1)
    },

    onBtnClicked() {
      $('#fullevents').css('display', 'none')
    },
    iframeRE() {
      var a = document.getElementById('iframe').src
      frame.location.replace(a)
    },
    handleScrollStart() {
      this.timer && clearTimeout(this.timer)
      this.timer = setTimeout(() => {
        this.handleScrollEnd()
      }, 300)
      this.currentTop =
        document.documentElement.scrollTop || document.body.scrollTop
      if (this.left > this.clientWidth / 2) {
        this.left = this.clientWidth - this.itemWidth / 2
      } else {
        this.left = -this.itemWidth / 2
      }
    },
    handleScrollEnd() {
      let scrollTop =
        document.documentElement.scrollTop || document.body.scrollTop
      if (scrollTop === this.currentTop) {
        if (this.left > this.clientWidth / 2) {
          this.left = this.clientWidth - this.itemWidth - this.gapWidth
        } else {
          this.left = this.gapWidth
        }
        clearTimeout(this.timer)
      }
    },

    eventClose() {
      this.eventVisible = false
    },
    homeFullClose() {
      $('#fullevents').css('display', 'none')
    },
    talkIframe() {
      var agUser = sessionStorage.getItem('CS_API_USER')
      var agKey = sessionStorage.getItem('CS_API_KEY')

      $('.directionMask').css('display', 'none')
      if (window.orientation == 0 || window.orientation == 180) {
        $('.directionMask').css('display', 'none')
      }
      window.onorientationchange = function() {
        if (window.orientation == 90 || window.orientation == -90) {
          $('.directionMask').css('display', 'block')
        } else {
          $('.directionMask').css('display', 'none')
        }
      }

      const vm = this
      vm.fullHeight = window.innerHeight
      vm.fullWidth = window.innerWidth
      window.onresize = () => {
        vm.fullHeight = window.innerHeight
        vm.fullWidth = window.innerWidth
      }
      $('.chatIframe').css('height', vm.fullHeight - 53 + 'px')
      $('.chatIframe').css('width', vm.fullWidth)

      var stest = {
        agentuser: sessionStorage.getItem('CS_API_USER'),
        agentkey: sessionStorage.getItem('CS_API_KEY')
      }

      var signData = JSON.stringify({
        thirdpartyId: window.sessionStorage.getItem('username'),
        agentuser: sessionStorage.getItem('CS_API_USER'),
        agentkey: sessionStorage.getItem('CS_API_KEY'),
        lang: localStorage.getItem('LOCALE'),
        templateMain: sessionStorage.getItem('Template'),
        EventMode: sessionStorage.getItem('eventMode')
      })

      let signDataSecret = [REDACTED]

      this.iframeSrc =
        this.iframeSrc + signDataSecret.replace(/\//g, '5s1g7er0A')
    }
  }
}
</script>

<style scoped>
.all {
  width: 100%;
  height: 100%;
  background-color: #fff;
}
.noscroll {
  overflow: hidden !important;
  color: #fff;
}
</style>
