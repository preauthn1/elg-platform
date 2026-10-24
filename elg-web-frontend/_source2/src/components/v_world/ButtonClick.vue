<template>
  <div class="Game">
    <!-- <LoginHeader></LoginHeader> -->
    <div class="gameLoginHe">
      <LoginHeader></LoginHeader>
    </div>
    <!-- <div class="GamePlay">-->
    <!-- <iframe
        width="100%"
        height="100%"
        frameborder="0"
        scrolling="auto"
        class="iframeG"
        id="cnvs_content"
        :src="iframeSrc"
    ></iframe>-->
    <!-- <link rel="import" href="https://example.invalid" /> -->
    <!-- <embed :src="iframeSrc" width="100%" height="100%" /> -->
    <!-- </div> -->

    <!-- <button style="color:#000;" @click="jump()">請按進入</button> -->
    <a :href="iframeSrc" target="_blank">
      <!-- <div class="enterV4">按此進入</div> -->
      <div class="buttonEnter">
        <input type="button" value="按此進入" style="width:150px;height:50px;" class="enterV4" />
      </div>
    </a>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import axios from 'axios'
import LoginHeader from '@/components/v_world/Login_header'
import secret from '@/utils/secret'
import md5 from 'js-md5'
import Global from '@/Global'
function changeFrameHeight() {
  var iframe = document.getElementById('Gameiframe')
  iframe.height = iframe.contentDocument.body.clientHeight - 60
}
window.onresize = function() {
  changeFrameHeight()
}

export default {
  components: {
    maskModal
  },
  data() {
    return {
      iframeSrc: ''
    }
  },
  components: {
    LoginHeader
  },
  mounted() {
    $('.directionMask').css('display', 'none')
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
    let md5_key = Global.$API_MD5_KEY
    let vendor_id = sessionStorage.getItem('vendor_id')
    let jsonMsg = JSON.stringify({
      vendor_id: vendor_id
    })
    let params = secret.Encrypt(jsonMsg)
    let key = md5(params + md5_key)
    axios
      .post(
        Global.$API_URL + 'api/vendorInfo',

        {
          params: params,
          key: key
        },
        {
          headers: {
            'Accept-Language': localStorage.getItem('LOCALE'),
            Authorization: 'Bearer ' + sessionStorage.getItem('token')
          }
        }
      )
      .then(res => {
        //判斷IOS ANDROID
        // $('.iframeG').attr('src', res.data.data.url)
        this.iframeSrc = res.data.data.url
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
    jump() {},

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
