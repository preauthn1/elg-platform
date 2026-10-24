<template>
  <div class="Events_header clearfix">
    <div class="backBtn float-left">
      <img src="../../assets/img/v_world/back1.png" @click="back()" v-if="$route.meta.back" />
    </div>
    <div class="EventsHeaderTitle float-left">{{ $t($route.meta.title) }}</div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
export default {
  computed: {
    ...mapGetters(['getComplete_visible', 'getTitle'])
  },
  data() {
    return {
      isplayed: true
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
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
