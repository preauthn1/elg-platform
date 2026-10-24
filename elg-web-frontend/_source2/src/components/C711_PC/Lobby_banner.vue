<template>
  <div class="Lobby_banner">
    <div class="banner_block">
      <b-carousel id="carousel-fade" controls indicators img-width="100%" img-height="100%">
        <div></div>
        <b-carousel-slide
          class="bannerSize"
          v-for="(banner,i) in getBanner"
          :key="i"
          :img-src="banner.image_pc"
          v-if="banner.image_pc!==''&&banner.image_pc!==null&&banner.image_pc!=='null'"
        >
          <div class="playBanner" @click="goList(banner)"></div>
        </b-carousel-slide>
      </b-carousel>
    </div>
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

          <span>{{lottery_pool[i]}}</span>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import Bus from './Bus.js'
export default {
  computed: {
    ...mapGetters([
      'getBanner',
      'getLottery',
      'getLobby_Vendors',
      'getAcctInfo',
      'getVendors'
    ])
  },
  data() {
    return {
      gameId: '',
      lottery_pool: [],
      firstEmit: true,
      vendors_type: []
    }
  },
  mounted() {},
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
        _this.$store.dispatch('action_lottery', 8)
      }, 600000)
    }
  },
  methods: {
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
    },
    goList(banner) {
      if (banner.game_id > 0) {
        if (banner.vendor_id == null) {
          var bannerVendorId = 0
        }
        this.$router.push(
          '/Game?id=' + banner.game_id + '&vendorID=' + bannerVendorId
        )
        ga('send', 'event', {
          eventCategory: 'LobbyHeader',
          eventAction: 'Carousel_Click',
          eventLabel: banner.image,
          eventValue: banner.game_id
        })
        // 進列表
      } else if (banner.game_type_id > 0) {
        let vendor_id = banner.vendor_id
        var vendorAry = this.getLobby_Vendors.filter(function(ary) {
          return ary.id == vendor_id
        })
        let sessionVendorAry = JSON.stringify(vendorAry)

        sessionStorage.setItem('vendors_type', sessionVendorAry)
        this.$router.push('/gameList?vds=' + vendor_id)
      } else if (banner.vendor_id > 0) {
        var Lobby_Vendors = this.getLobby_Vendors
        var vendor = this.getVendors
        var store = this.$store
        var router = this.$router

        $.each(vendor, function(key, value) {
          if (banner.vendor_id == value.id) {
            if (value.need_login == 0) {
              if (value.only_one_game == 1) {
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
                    if (banner.id == undefined) {
                      var gameId = 0
                    } else {
                      var gameId = banner.id
                    }

                    let vendorID = banner.vendor_id
                    sessionStorage.setItem('vendor_id', banner.vendor_id)
                    sessionStorage.setItem('vendor_name', banner.vendor_name)
                    router.push('/Game?id=' + gameId + '&vendorID=' + vendorID)
                    store.dispatch('action_vendorInfo', banner.vendor_id)
                  }
                })
              } else {
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
                setTimeout(() => {
                  let sessionVendors = [] // 季session
                  let idAry = [] // 傳路由 空動作為了gamelist能watch
                  vendorAry.forEach(element => {
                    sessionVendors.push(element)
                    idAry.push(element.id)
                  })
                  sessionStorage.setItem(
                    'vendors_type',
                    JSON.stringify(sessionVendors)
                  )

                  // banner進入gamelist是1
                  router.push('/GameList/' + banner.vendor_id + '/' + 1)
                  Bus.$emit('vendorID', banner.vendor_id)
                }, 100)
              }
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
        this.$router.push(
          '/EventDescription?id=' + banner.event_id + '&Banner=' + 2
        )
      }
    }
  },
  created() {
    this.$store.dispatch('actionBanner', 1)
    this.$store.dispatch('action_lottery', 8)
  }
}
</script>