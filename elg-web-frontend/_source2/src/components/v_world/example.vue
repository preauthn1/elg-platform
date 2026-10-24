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
        id="cnvs_content"
      ></iframe>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
// import '@/assets/css/v_world/style.css'
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
      iframeSrc: ''
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
        'https://example.invalid/api/getGameUrl',
        // 'https://example.invalid/api/vendorInfo',
        // 'https://example.invalid/api/vendorInfo',
        {
          params: 'k2MB+gpcv1Xf3hrH0NKjsRbZ6OTC9rPetbReVQwghH0=',
          key: '85994d46e225d3d032330b126f9b24c5'
        },
        {
          headers: {
            'Accept-Language': localStorage.getItem('LOCALE'),
            Authorization: 'Bearer ' + sessionStorage.getItem('token')
          }
        }
      )
      .then(res => {
        window.open(res.data.data)
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
