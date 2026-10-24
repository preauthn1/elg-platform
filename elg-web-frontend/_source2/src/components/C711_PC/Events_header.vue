<template>
  <div class="page">
    <div class="pageTitle">
      <img src="../../../static/img/C711_PC/pageTitle.png" />
      <div class="pageTitleAll clearfix">
        <div class="loginbarBack float-left">
          <img src="../../../static/img/C711_PC/back.png" @click="back()" v-if="$route.meta.back" />
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
import '../../assets/scss/C711_PC/css.css'

export default {
  computed: {
    ...mapGetters(['getComplete_visible', 'getTitle'])
  },
  data() {
    return {
      isplayed: true
    }
  },
  mounted() {},
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
