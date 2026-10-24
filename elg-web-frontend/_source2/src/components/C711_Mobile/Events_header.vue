<template>
  <div id="C711Mobile">
    <div class="page">
      <div class="pageTitle">
        <img src="../../assets/img/C711_Mobile/pageTitle.png" class="pagePic" />
        <div class="pageTitleAll clearfix">
          <div class="loginbarBack float-left">
            <img
              src="../../assets/img/C711_Mobile/back.png"
              @click="back()"
              v-if="$route.meta.back"
              id="lotteryback"
            />
          </div>
          <div class="gamePointTransfer float-left"></div>
          <div class="loginbarTitle float-left">
            <span>{{ $t($route.meta.title) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
import '../../assets/scss/C711_Mobile/css.css'
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

