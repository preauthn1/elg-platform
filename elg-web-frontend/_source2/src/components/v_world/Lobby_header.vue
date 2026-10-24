<template>
  <div class="lobbybar">
    <div class="lobbyHeader">
      <img src="../../assets/img/v_world/lobbyBg_S.png" />

      <div class="lobbybarTitle">{{ $t("lobby") }}</div>
      <div class="personal">
        <img src="../../assets/img/v_world/playerBg.png" class="personalImg" />
        <img src="../../assets/img/v_world/Xmas_IN.png" class="specialINT" />
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
                  <span>{{ getAcctInfo.balance }}</span>
                </div>
                <a
                  href="/StoredValue"
                  class="StoredBtn float-right"
                  onClick="ga('send', 'event', 'LobbyHeader', 'StoredBtn_Click', 'Stored');"
                >
                  <img src="../../assets/img/v_world/Gadd.png" id="StoredBtn" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="carouselPic">
      <div class="bannerImg">
        <b-carousel
          id="carousel-fade"
          style="text-shadow: 0px 0px 2px #000"
          fade
          img-width="100%"
          img-height="100%"
        >
          <b-carousel-slide
            class="re"
            v-for="(banner,i) in getBanner"
            :key="i"
            :img-src="banner.image"
            width="100%;"
            height="100%;"
            max-height="100%;"
          >
            <div class="ab" @click="goBanner(banner)"></div>
          </b-carousel-slide>
        </b-carousel>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import '../../assets/scss/v_world/style.css'
import LobbyHeader from '@/components/v_world/Lobby_header'

export default {
  components: {
    maskModal
  },
  computed: {
    ...mapGetters(['getBanner', 'getAcctInfo', 'getVendors'])
  },
  data() {
    return {
      Username: window.sessionStorage.getItem('username'),
      Nickname: window.sessionStorage.getItem('nickname'),
      balance: window.sessionStorage.getItem('balance'),
      Avatar: window.sessionStorage.getItem('avatar'),
      DefaultAvatar: require('../../assets/img/v_world/user.jpg'),
      fullWidth: 0,
      fullHeight: 0
    }
  },

  mounted() {
    // $('.Lobby_barAll').css('height', 18 + '%')
    $('.carouselPic').css('display', 'none')
    setTimeout(() => {
      var banner = this.getBanner
      //   var banner = []
      //   console.log(banner, 'baanner')
      let vm = this
      if (banner[0]) {
        vm.fullWidth = window.innerWidth
        vm.fullHeight = window.innerHeight
        $('.bannerImg').css('width', vm.fullWidth)
        $('.bannerImg').css('margin-left', -vm.fullWidth / 2)
        $('.img-fluid').css('height', vm.fullHeight / 5.5)
        window.onresize = () => {
          vm.fullWidth = window.innerWidth
          vm.fullHeight = window.innerHeight
        }
        $('.carouselPic').css('display', 'block')
        $('.bannerImg').css('display', 'block')
        if (window.screen.height > 736) {
          $('.Lobby_barAll').css('height', 32 + '%')
          $('.lobbybar').css('height', 32 + '%')
        } else {
          $('.Lobby_barAll').css('height', 38 + '%')
        }
      } else {
        // console.log('nobanner', vm.fullWidth)
        $('.carouselPic').css('display', 'none')
        $('.bannerImg').css('display', 'none')
        $('.Lobby_barAll').css('height', 18 + '%')
        $('.lobbybar').css('height', 18 + '%')
      }
    }, 1700)
  },
  methods: {
    goBanner(banner) {
      //   console.log(banner, 'banner')
      // 進遊戲
      if (
        banner.game_id !== 0 &&
        banner.game_type_id == 0 &&
        banner.vendor_id == 0
      ) {
        this.$router.push('/Game?id=' + banner.game_id)
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.game_id
        })
        // 進列表
      } else if (
        banner.game_type_id !== 0 &&
        banner.game_id == 0 &&
        banner.vendor_id == 0
      ) {
        sessionStorage.setItem('vendor_id', '')
        sessionStorage.setItem('type_id', banner.game_type_id)
        this.$router.push('/GameList')
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.game_type_id
        })
        //進遊戲商
      } else if (
        banner.vendor_id !== 0 &&
        banner.game_type_id == 0 &&
        banner.game_id == 0
      ) {
        var vendor = this.getVendors
        var store = this.$store
        var router = this.$router

        sessionStorage.setItem('vendor_id', banner.vendor_id)
        $.each(vendor, function(key, value) {
          if (banner.vendor_id === value.id) {
            // console.log(banner.vendor_id, value.id, '點下去ID')
            if (value.need_login == 0) {
              // need_login 是否需開app
              if (value.only_one_game == 1) {
                // console.log('ok')
                store.dispatch('actionOne_balance', value.id).then(balance => {
                  let vendor_balance = parseInt(
                    balance.toString().replace(',', ''),
                    10
                  )
                  if (vendor_balance < 0) {
                    this.vendorName = value.name
                    this.depositVal = Math.round(
                      parseInt(
                        this.vWorld_balance.toString().replace(',', ''),
                        10
                      ) / 2
                    )
                    this.depositVisible = true
                  } else {
                    router.push('/Game')
                    store.dispatch('action_vendorInfo')
                  }
                })
              } else {
                router.push('/GameList')
              }
            } else if (value.need_login == 1) {
              router.push('/GameApp?vendor=' + value.name) // ?vendor= for gameApp title //
            }
          }
        })
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.vendor_id
        })
      }
    }
  },
  created() {
    this.$store.dispatch('actionBanner', 1)
    var width = window.screen.width
    $('.bannerImg').css('width', width)
    $('.bannerImg').css('margin-left', width / 2)
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->

<style scoped>
.re >>> .carousel-caption {
  top: 0 !important;
  position: absolute;
  right: 0;
  bottom: 0;
  left: 0;
}
.re {
  position: relative;
}
.ab {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
}
</style>
