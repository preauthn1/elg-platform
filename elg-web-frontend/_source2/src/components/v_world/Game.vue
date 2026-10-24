<template>
  <div class="Game">
    <!-- <LoginHeader></LoginHeader> -->
    <div class="gameLoginHe">
      <LoginHeader></LoginHeader>
    </div>
    <div class="GamePlay">
      {{getVendor_Info.user}}
      <img
        id="loading"
        src="../../assets/img/v_world/loading.gif"
        class="loadingiframe"
        alt="loading gif"
      />
      <!-- 一般遊戲 -->

      <iframe
        name="target"
        width="100%"
        height="100%"
        frameborder="0"
        scrolling="auto"
        class="iframeG"
        id="iframeG"
      ></iframe>

      <!-- 豪龍用 -->
      <iframe
        width="100%"
        height="100%"
        frameborder="0"
        scrolling="auto"
        class="iframeG"
        id="iframe4"
      ></iframe>

      <!-- 一般遊戲 joker submit開法 -->
      <form method="post" target="target" id="joker">
        <input :value="getVendor_Info.user" name="user" type="hidden" />
        <input :value="getVendor_Info.key" name="key" type="hidden" />
        <input :value="getVendor_Info.extension1" name="extension1" type="hidden" />
      </form>
      <!-- vendor單一遊戲 lobby直進 -->
      <form method="post" target="target" id="onlyOneGame"></form>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
    <!--content-->
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import LoginHeader from '@/components/v_world/Login_header'

export default {
  data() {
    return {
      vendor_id: window.sessionStorage.getItem('vendor_id'),
      screenHeight: window.innerHeight //iframe高度
      //   document.body.clientHeight
    }
  },
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getGameUrl', 'getVendor_Info'])
  },
  mounted() {
    // console.log(this.vendor_id, 'id')
    $('.directionMask').css('display', 'none')

    //iframe高度
    const that = this
    window.onresize = () => {
      return (() => {
        window.screenHeight = window.innerHeight
        that.screenHeight = window.screenHeight
      })()
    }
    // console.log(that.screenHeight, '在哪')

    //第一次
    var css = {
      height: that.screenHeight - 44 + 'px'
    }
    // console.log(css, '進入時的高')
    $('.GamePlay').css(css)
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        //   橫
        // console.log('橫的')
        $('.directionMask').css('display', 'none')
      } else {
        //   直
        // console.log('直')
        $('.directionMask').css('display', 'none')
      }
    }
    $(document).ready(function() {
      $('.iframeG').on('load', function() {
        $('#loading').hide()
        var enterGameName = sessionStorage.getItem('enterGameName')
        var enterGameId = sessionStorage.getItem('enterGameId')
        // var engerVendorName = sessionStorage.getItem('vendor_name')
        // var engerVendorId = sessionStorage.getItem('vendor_id')

        ga('send', 'event', {
          eventCategory: 'Game',
          eventAction: 'Game_Enter',
          eventLabel: enterGameName,
          eventValue: enterGameId
        })
      })
    })
    // console.log(this.vendor_id)
    if (this.vendor_id == 4) {
      $('#iframe4').css('display', 'block')
      $('#iframeG').css('display', 'none')
    } else {
      $('#iframe4').css('display', 'none')
      $('#iframeG').css('display', 'block')
    }
  },
  //監聽iframe高度
  watch: {
    screenHeight(val) {
      // 为了避免频繁触发resize函数导致页面卡顿，使用定时器
      if (!this.timer) {
        // 一旦监听到的screenHeight值改变，就将其重新赋给data里的screenHeight
        this.screenHeight = val
        this.timer = true
        let that = this
        setTimeout(function() {
          // 打印screenWidth变化的值
          //   console.log(that.screenHeight, '監聽畫面高')
          that.timer = false
        }, 400)
        $('.directionMask').css('display', 'none')
        var css = {
          height: that.screenHeight - 44 + 'px'
        }
        // console.log(css, '監聽高-44')
        $('.GamePlay').css(css)
      }
    }
  },

  methods: {
    game() {
      let query = window.location.search.split('=')
      let id = query[1]
      if (id) {
        this.$store.dispatch('actionGameUrl', id)
      } else {
        this.$store.dispatch('action_vendorInfo')
      }
    },
    dialogClose() {
      this.show = false
      this.$emit('listenToConfig', false)
    }
  },

  created() {
    this.game()
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
