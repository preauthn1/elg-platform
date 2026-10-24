<template>
  <div id="G733">
    <div class="G733_Mcarousel">
      <div class="carouselPic">
        <div class="bannerImg">
          <!-- MOBILE BANNER -->
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
              <!-- banner 點擊用 -->
              <div class="ab" @click="goBanner(banner)"></div>
            </b-carousel-slide>
          </b-carousel>
        </div>
      </div>
    </div>
    <!-- PC版彩池 -->
    <div class="Lobby_banner">
      <div class="Lottery_block">
        <div class="Lottery_bar">
          <div
            class="Lottery_item"
            v-for="(lottery, i) in getLottery"
            :key="i"
            @click="playGame(lottery)"
          >
            <div class="LotteryBg">
              <img :src="lottery.image" />
            </div>

            <span style="color:#fff;">{{lottery_pool[i]}}</span>
          </div>
        </div>
      </div>
      <!-- PC版 BANNER -->
      <div class="banner_block">
        <b-carousel id="carousel-fade" controls indicators img-width="100%" img-height="100%">
          <div></div>
          <b-carousel-slide
            class="bannerSize"
            v-for="(banner,i) in getBanner"
            :key="i"
            :img-src="banner.image_pc"
            v-if="banner.image_pc!=''&&banner.image_pc!=null"
          >
            <div class="playBanner" @click="goBanner(banner)"></div>
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
import Bus from './Bus.js'
export default {
  computed: {
    ...mapGetters([
      'getBanner',
      'getAcctInfo',
      'getVendors',
      'getLobby_Vendors',
      'getBanner4',
      'getLottery'
    ])
  },
  components: {
    maskModal
  },
  data() {
    return {
      gameId: '',
      lottery_pool: [],
      firstEmit: true,
      vendors_type: []
    }
  },
  watch: {
    getLottery(lotterys) {
      let _this = this
      this.lottery_pool = []
      lotterys.forEach(e => {
        let v = e.lottery_pool
        _this.lottery_pool.push(v)
      })
      let l = this.lottery_pool.length
      let int = setInterval(() => {
        for (let i = 0; i < l; i++) {
          let old = parseFloat(_this.lottery_pool[i])
          let random = (Math.random() * 1) / 10
          let plus = old + random
          let p = Math.round(plus * 1000) / 1000
          _this.lottery_pool.splice(i, 1, p.toFixed(3))
        }
      }, 300)
      setTimeout(() => {
        clearInterval(int)
        _this.$store.dispatch('action_lottery', 13)
      }, 600000)
    }
  },
  mounted() {
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    // 手機橫向禁止
    ;(window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }),
      //banner
      $('.carouselPic').css('display', 'block')
  },

  methods: {
    //   點擊banner
    goBanner(banner) {
      // 進遊戲
      if (banner.game_id > 0) {
        if (banner.vendor_id == null) {
          var bannerVendorId = 0
        }
        this.$router.push('/Game?id=' + banner.game_id)
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.game_id
        })
        // 進列表
      } else if (banner.game_type_id > 0) {
        sessionStorage.setItem('vendor_id', '')
        sessionStorage.setItem('type_id', banner.game_type_id)
        this.$router.push('/GameList/' + banner.game_type_id)
        window.location.reload()
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.game_type_id
        })
        //進遊戲商
      } else if (banner.vendor_id > 0) {
        var Lobby_Vendors = this.getLobby_Vendors
        var vendor = this.getVendors
        var store = this.$store
        var router = this.$router
        sessionStorage.setItem('vendor_id', banner.vendor_id)
        $.each(vendor, function(key, value) {
          if (banner.vendor_id == value.id) {
            if (value.need_login == 0) {
              // need_login 是否需開app
              if (value.only_one_game == 1) {
                store.dispatch('actionOne_balance', value.id).then(balance => {
                  let vendor_balance = parseInt(
                    balance.toString().replace(',', ''),
                    10
                  )
                  //   餘額不足轉入遊戲商彈窗
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
                    // 目前只有豪龍 sbo
                    router.push('/Game?id=' + banner.vendor_id)
                    store.dispatch('action_vendorInfo', banner.vendor_id)
                  }
                })
                // GA
                ga('send', 'event', {
                  eventCategory: 'LobbyHeader',
                  eventAction: 'Carousel_Click',
                  eventLabel: banner.image,
                  eventValue: banner.vendor_id
                })
              }
              //    遊戲列表BANNER進不同的遊戲列表
              else {
                let vendorAry = Lobby_Vendors.filter(function(res) {
                  return res.id == banner.vendor_id
                })
                let session_vendor_name = vendorAry[0].name
                sessionStorage.setItem('vendor_id', banner.vendor_id)
                sessionStorage.setItem('vendor_name', session_vendor_name)

                let listData = {
                  vendor_id: banner.vendor_id,
                  page: 1,
                  per_page: 1000
                }
                store.dispatch('action_myGames', listData)
                router.push('/GameList/' + banner.vendor_id)

                ga('send', 'event', {
                  eventCategory: 'LobbyHeader',
                  eventAction: 'Carousel_Click',
                  eventLabel: banner.image,
                  eventValue: banner.vendor_id
                })
              }
            } else if (value.need_login == 1) {
              // need_login 進 APP
              router.push(
                '/GameApp?vendor=' + value.name + '&vendorID=' + value.id
              ) // ?vendor= for gameApp title //
              ga('send', 'event', {
                eventCategory: 'LobbyHeader',
                eventAction: 'Carousel_Click',
                eventLabel: banner.image,
                eventValue: banner.vendor_id
              })
            }
          }
        })
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.vendor_id
        })
      } else if (
        banner.vendor_id == 0 &&
        banner.game_type_id == 0 &&
        banner.game_id == 0
      ) {
        window.location = banner.url
      } else if (banner.event_id > 0) {
        this.$router.push('/EventDescription?id=' + banner.event_id)
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.event_id
        })
      }
    },
    // PC 彩池 進遊戲
    playGame(list) {
      if (!list.id) {
        this.gameId = list.game_id
      } else {
        this.gameId = list.id
      }
      let vendorID = list.vendor_id
      sessionStorage.setItem('vendor_id', list.vendor_id)
      sessionStorage.setItem('vendor_name', list.vendor_name)

      this.$router.push('/Game?id=' + this.gameId + '&vendorID=' + vendorID)
    }
  },
  created() {
    this.$store.dispatch('actionBanner', 1)
    this.$store.dispatch('action_lottery', 13)
    var width = window.screen.width
    $('.bannerImg').css('width', width)
    $('.bannerImg').css('margin-left', width / 2)
  }
}
</script>
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