<template>
  <div class="lobbybar">
    <div class="lobbyHeader">
      <img src="../../assets/img/v_world/lobbyBg_S.png" />
      <div class="lobbybarTitle">{{ $t("lobby") }}</div>
      <div class="personal">
        <img src="../../assets/img/v_world/playerBg.png" />
        <div class="personalUser clearfix">
          <div class="float-left personalLeft">
            <img
              class="img-rounded img-responsive center-block"
              :src="Avatar==='null'?DefaultAvatar:Avatar"
            />
          </div>
          <div class="float-left personalRight">
            <div class="lobbyInputBg">
              <img src="../../assets/img/v_world/textbox.png" />
              <div class="lobbyFontBtn">
                {{ $t("nickname") }}:
                <span>{{ Nickname!== undefined?Nickname:Username }}</span>
              </div>
            </div>
            <div class="lobbyInputBg">
              <img src="../../assets/img/v_world/textbox.png" />
              <div class="lobbyFontBtn clearfix">
                <div class="lobbyFont float-left">
                  {{ $t("points") }}:
                  <span>{{ balance }}</span>
                </div>
                <a href="/StoredValue" class="StoredBtn float-right">
                  <img src="../../assets/img/v_world/Gadd.png" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="carouselPic">
      <div class="bannerImg">
        <a href="/Events">
          <b-carousel
            id="carousel-fade"
            style="text-shadow: 0px 0px 2px #000"
            fade
            indicators
            img-width="100%"
            img-height="40%"
          >
            <b-carousel-slide
              v-for="(banner,i) in getBanner"
              :key="i"
              :img-src="banner.image"
              width="100%;"
              height="40%;"
            ></b-carousel-slide>
          </b-carousel>
        </a>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
// import '@/assets/css/v_world/style.css'
// import '../../assets/scss/v_world/style.css'
import LobbyHeader from '@/components/v_world/Lobby_header'
export default {
  computed: {
    ...mapGetters(['getBanner'])
  },
  data() {
    return {
      Username: window.sessionStorage.getItem('username'),
      Nickname: window.sessionStorage.getItem('nickname'),
      balance: window.sessionStorage.getItem('balance'),
      Avatar: window.sessionStorage.getItem('avatar'),
      DefaultAvatar: require('../../assets/img/v_world/user.jpg')
    }
  },
  created() {
    this.$store.dispatch('actionBanner', 1)
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped src="../../assets/scss/v_world/style.css">
</style>
