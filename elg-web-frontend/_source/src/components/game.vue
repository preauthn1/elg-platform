<template>
  <div class="gameall">
    <div v-if="$store.state.vendor_id == 3" class="iframe">
      <h1>{{ $store.state.urlParams }}</h1>
    </div>
    <div class="tagB">
      <router-link to="/events">
        <img src="../assets/discount.png" border="0" />
      </router-link>
    </div>
    <div class="tagS">
      <router-link to="/events">
        <img src="../assets/YButtonS.png" border="0" />
      </router-link>
    </div>
    <div class="gamePlay">
      <iframe
        v-if="$store.state.vendor_id == 2"
        src="http://example.invalid"
        width="100%"
        height="590px"
        frameborder="0"
      ></iframe>
      <iframe v-else :src="gameUrl" width="100%" height="590px" frameborder="0"></iframe>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
export default {
  computed: {
    ...mapGetters({
      gameUrl: 'getGameUrl'
    })
  },
  methods: {
    ...mapActions({
      actionJokerGame: 'actionJokerGame'
    })
  },
  mounted() {
    this.$store.dispatch('actionJokerGame')
    this.$store.dispatch('actionBanner', 1)
  }
}
</script>
<style lang="scss">
.gameall {
  height: 100%;
  padding-top: 150px;
  width: 65%;
  margin: auto;
  h1 {
    font-size: 20px;
  }
}
@media screen and (max-width: 650px) {
  .gameall {
    width: 100%;
  }
}
.bannerImg {
  width: 100%;
  height: inherit;
  min-height: 360px;
  min-width: 1400px;
}
.tagB {
  position: fixed;
  right: 0px;
  top: 150px;
  z-index: 500;
  display: block;
}
@media screen and(max-width:650px) {
  .tagB {
    display: none;
  }
}
.tagS {
  position: fixed;
  right: 0px;
  top: 150px;
  z-index: 500;
  display: none;
}
@media screen and(max-width:650px) {
  .tagS {
    display: block;
  }
}
.gamePlay {
  border: 10px dashed #ffe63d;
  height: 100%;
}
@media screen and (max-width: 650px) {
  .gamePlay {
    border: none;
    width: 100%;
    height: auto;
    border: 2px dashed #ffe63d;
  }
}
.bottom {
  padding-bottom: 240px;
}
@media screen and (max-width: 650px) {
  .bottom {
    padding-bottom: 0px;
  }
}
</style>
