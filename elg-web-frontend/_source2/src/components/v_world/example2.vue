<template>
  <div class="Game">
    <!-- <LoginHeader></LoginHeader> -->
    <div class="gameLoginHe">
      <LoginHeader></LoginHeader>
    </div>
    <div class="GamePlay">
      <iframe
        name="target"
        width="100%"
        height="100%"
        frameborder="0"
        scrolling="auto"
        class="iframeG"
        id="iframeG"
      ></iframe>
      <form method="post" target="target" id="joker">
        <input :value="user" name="user" type="hidden" />
        <input :value="sessionID" name="key" type="hidden" />
        <input :value="tokenCode" name="extension1" type="hidden" />
      </form>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'

import axios from 'axios'
import LoginHeader from '@/components/v_world/Login_header'

function changeFrameHeight() {
  var iframe = document.getElementById('Gameiframe')
  iframe.height = iframe.contentDocument.body.clientHeight - 60
}
window.onresize = function() {
  changeFrameHeight()
}
export default {
  data() {
    return {
      iframeSrc: '',
      user: '',
      sessionID: '',
      tokenCode: ''
    }
  },
  components: {
    LoginHeader
  },
  mounted() {
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'none')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
    axios
      .post(
        // 'https://example.invalid/api/getGameUrl',
        //  'https://example.invalid/api/getGameUrl',
        'https://example.invalid/api/vendorInfo',
        // 'https://example.invalid/api/vendorInfo',
        {
          params: 'uKnmyGz2MdqORaNCyue/rj3G8q6Iwwq5sUZ4fAVX1/s=',
          key: 'b9c39f9b240b08615dad1a018633336a'
        },
        {
          headers: {
            'Accept-Language': localStorage.getItem('LOCALE'),
            Authorization: 'Bearer ' + sessionStorage.getItem('token')
          }
        }
      )
      .then(res => {
        let url = res.data.data.url
        let wap = url.split('/')[3]
        let a0 = url.split('/')[0]
        let a1 = url.split('/')[1]
        let a2 = url.split('/')[2]
        let a4 = url.split('/')[4]
        let main = a4.split('?')[0]
        let aa = a0 + '/' + a1 + '/' + a2 + '/' + wap + '/' + main + '?'
        // console.log('aa', aa)
        let b = url.split('?')[1]
        let user = b.split('&')[0]
        let user1 = user.split('=')[1]
        let sessionID = b.split('&')[1]
        let sessionID1 = sessionID.split('=')[1]
        let tokenCode = b.split('&')[2]
        let tokenCode1 = tokenCode.split('=')[1]
        // console.log(
        //   aa +
        //     'user=' +
        //     user1 +
        //     '&' +
        //     'sessionID=' +
        //     sessionID1 +
        //     '&' +
        //     'tokenCode=' +
        //     tokenCode1
        // )

        this.user = user1
        this.sessionID = sessionID1
        this.tokenCode = tokenCode1
        $('#joker').attr('action', aa)
        // console.log(res.data.data.url, 'joker')
        setTimeout(() => {
          $('#joker').submit()
          // console.log('submit')
        }, 2000)
      })
      .catch(error => {
        console.log('error', error)
      })

    //iframe高度
    const vm = this
    vm.fullHeight = window.innerHeight
    window.onresize = () => {
      vm.fullHeight = window.innerHeight
    }
    var css = {
      height: vm.fullHeight - 45 + 'px '
    }
    setTimeout(() => {
      $('.GamePlay').css(css)
    }, 200)
  },
  methods: {
    game() {
      let query = window.location.search.split('=')
      let id = query[1]
      if (id) {
        this.$store.dispatch('actionGameUrl', id)
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
