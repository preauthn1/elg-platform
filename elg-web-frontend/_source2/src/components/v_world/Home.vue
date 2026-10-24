<template>
  <div class="contentAll">
    <div
      v-if="$route.meta.event"
      class="ys-float-btn"
      :style="{'width':itemWidth+'px','height':itemHeight+'px','left':left+'px','top':top+'px'}"
      ref="myEvents"
      id="myEvents"
      @click="onBtnClicked()"
    >
      <slot name="icon"></slot>
      <div
        class="eventbtn"
        data-toggle="modal"
        data-target="#eventVisible"
        onClick="ga('send', 'event', 'Lobby', 'EventBtn_Click', 'Event');"
      >
        <img src="../../assets/img/v_world/xmas.png" class="specialButton" />
        <img src="../../assets/img/v_world/mark.gif" class="eventBtnT" v-if="get_EventsReddeem===1" />
        <span class="eventFont">{{ get_EventsTotal }}</span>
        <img src="../../assets/img/v_world/eventBtn.png" />
      </div>
    </div>
    <!-- EVENTS 彈窗 -->

    <div class="modal fade" id="eventVisible" tabindex="-1" role="dialog">
      <div class="modal-dialog modal-dialog-scrollable" role="document">
        <div class="modal-content">
          <div class="colse closeBtn" data-dismiss="modal" aria-label="Close" @click="iframeRE()">
            <img src="../../assets/img/v_world/closeBtn.png" />
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
    <footer v-if="$route.meta.footer">
      <img src="../../assets/img/v_world/Xmas_BOTTOM.png" class="specialINB" />
      <div class="lobbyMenu">
        <div class="menuBtn">
          <a href="/">
            <img src="../../assets/img/v_world/lobby4.png" v-if="$route.meta.lobbyBtn" />
            <img src="../../assets/img/v_world/lobby5.png" v-if="$route.meta.lobbyBtnLight" />
            <span>{{ $t("lobby") }}</span>
          </a>
        </div>
        <div class="menuBtn">
          <a href="/Talk">
            <!-- <img src="../../assets/img/v_world/red.png" class="talkBtn" /> -->
            <img src="../../assets/img/v_world/talk4.png" v-if="$route.meta.talkBtn" />
            <img src="../../assets/img/v_world/talk5.png" v-if="$route.meta.talkBtnLight" />
            <span>{{ $t("talk") }}</span>
          </a>
        </div>
        <div class="menuBtn">
          <a href="/wallet">
            <img src="../../assets/img/v_world/wallet4.png" v-if="$route.meta.walletBtn" />
            <img src="../../assets/img/v_world/wallet5.png" v-if="$route.meta.walletBtnLight" />
            <span>{{ $t("wallet") }}</span>
          </a>
        </div>
        <div class="menuBtn">
          <a href="/Setting">
            <img src="../../assets/img/v_world/set4.png" v-if="$route.meta.settingBtn" />
            <img src="../../assets/img/v_world/set5.png" v-if="$route.meta.settingBtnLight" />
            <span>{{ $t("set") }}</span>
          </a>
        </div>
      </div>
    </footer>
  </div>
</template>
<script>
// eslint-disable-next-line no-unused-vars

import Events from '@/components/v_world/Events'
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
import { runInDebugContext } from 'vm'
import { setTimeout } from 'timers'
import '../../assets/scss/v_world/style.css'
//ios back reload
window.addEventListener('pageshow', function(e) {
  var isIOS = navigator.userAgent.indexOf('iPhone') > -1
  if (isIOS && localStorage.getItem('isNeedRefresh')) {
    localStorage.removeItem('isNeedRefresh')
    window.location.reload()
  }
})

// $(window).resize(function(event) {
//   if ($('footer').hasClass('p_static')) {
//     $('footer').removeClass('p_static')
//   } else {
//     $('footer').addClass('p_static')
//   }
// })

export default {
  name: 'FloatImgBtn',
  components: {
    Events
  },
  computed: {
    ...mapGetters(['get_EventsTotal', 'get_Events', 'get_EventsReddeem'])
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
      f: false
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
    if (sessionStorage.getItem('token')) {
      this.$store.dispatch('action_EventList')
    }
  },
  mounted() {
    // console.log(this.get_EventsReddeem)
    window.addEventListener('scroll', this.handleScrollStart)
    this.$nextTick(() => {
      const div = this.$refs.myEvents
      //   addEventListener bug test
      //   const div = document.getElementById('myEvents')
      if (div) {
        // console.log(div)
        div.addEventListener('touchstart', () => {
          div.style.transition = 'none'
        })
        div.addEventListener('touchmove', e => {
          if (e.targetTouches.length === 1) {
            let touch = event.targetTouches[0]
            this.left = touch.clientX - this.itemWidth / 2
            example.invalid = touch.clientY - this.itemHeight / 2
          }

          // $('#app').addClass('noscroll')
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
      //   this.$emit('onFloatBtnClicked')
      //   iframe bug test
      // if(!this.f) {
      //     console.log('1')
      //     this.f = true
      //     $('#iframe').attr('src','/Events_i')
      // } else {
      //     console.log('2')
      //     $('#iframe').attr('src','/Events_i')
      // }
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
    // EventBtn() {
    //   this.eventVisible = true
    // },
    eventClose() {
      this.eventVisible = false
    }
  }
}
</script>

<style  scoped>
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
