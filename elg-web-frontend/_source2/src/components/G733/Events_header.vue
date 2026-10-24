<template>
  <div id="G733">
    <div class="LoginHeader gary_header page clearfix">
      <div class="pageTitleAll clearfix">
        <div class="loginbarBack float-left">
          <img src="../../assets/img/G733/back.png" @click="back()" v-if="$route.meta.back" />
        </div>
        <div class="gamePointTransfer float-left"></div>
        <div class="loginbarTitle float-left">
          <span>{{ $t($route.meta.title) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
import '../../assets/scss/G733/style.css'
import '../../assets/scss/G733/css.css'
export default {
  computed: {
    ...mapGetters(['getComplete_visible', 'getTitle'])
  },
  data() {
    return {
      isplayed: true
    }
  },
  mounted() {
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
  },
  methods: {
    back() {
      window.history.go(-1)
      //   音效播放
      var cardSound = document.getElementById('card')
      if (this.isplayed) {
        cardSound.pause()
        cardSound.currentTime = 0
        this.isplayed = false
      }
    },
    goLobby() {
      window.location = '/'
    }
  }
}
</script>

