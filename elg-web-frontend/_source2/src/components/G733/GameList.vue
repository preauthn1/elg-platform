<template>
  <div id="G733">
    <div class="points_Header">
      <PointsHeader></PointsHeader>
    </div>
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2 GameNoB">
          <!-- 全部遊戲 -->
          <div class="gameList">
            <div class="vendorName"></div>
            <div class="HotNewGame">
              <swiper :options="mobilepc=='PC'?swiperOptionHotNew_PC:swiperOptionHotNew">
                <swiper-slide
                  class="swiperPic"
                  v-for="(list,i) in getGameList"
                  :key="i"
                  v-if="list.is_new == 1|| list.is_hot == 1"
                >
                  <div class="gamePicAll" @click="playGame(list)">
                    <img src="../../assets/img/G733/bigImg.png" class="GameItemB" />
                    <img
                      src="../../assets/img/G733/NewBtn.png"
                      class="NewHotBtn"
                      v-if="list.is_new == 1"
                    />
                    <img
                      src="../../assets/img/G733/HotBtnL.png"
                      class="NewHotBtn"
                      v-if="list.is_hot == 1"
                    />
                    <img
                      src="../../assets/img/G733/Gift.png"
                      class="Gift"
                      v-if="list.event_bet||list.event_win"
                    />
                    <div class="logoFrame">
                      <img src="../../assets/img/G733/logoFrame.png" class="logoBg" />
                      <img class="logoPic" :src="vendorLogo(list.vendor_id)" />
                    </div>
                    <div class="gameListCon">
                      <img :src="list.image" class="gamePic" />
                    </div>
                  </div>
                  <div class="gameName">{{ list.name }}</div>
                </swiper-slide>
              </swiper>
            </div>
            <div class="vendorGame clearfix">
              <div
                class="float-left oneGameAll"
                v-for="(list,i) in getGameList"
                :key="i"
                @click="playGame(list) "
              >
                <div class="oneGame">
                  <img src="../../assets/img/G733/smallImg.png" class="oneGameBG" />
                  <img
                    src="../../assets/img/G733/Gift.png"
                    class="oneGameGift"
                    v-if="list.event_bet||list.event_win"
                  />

                  <div class="oneGamelogo">
                    <img src="../../assets/img/G733/logoFrame.png" class="logoBg" />
                    <img class="logoPic" :src="vendorLogo(list.vendor_id)" />
                  </div>
                  <div class="oneGameCon">
                    <img :src="list.image" class="oneGamePic" />
                  </div>
                </div>
                <div class="oneGameName">{{ list.name }}</div>
              </div>
            </div>
            <!-- 一般頁數 -->
            <div style="margin:15px auto; font-size:13px;">
              <div
                class="Pagination"
                v-if="getGamePagination.length !== 0 && getGamePagination.total !== 0"
              >
                <div
                  @click="getList(1)"
                  v-if="getGamePagination.previous !== ''"
                >{{$t('firstPage')}}</div>
                <div
                  @click="getList(getGamePagination.previous)"
                  v-if="getGamePagination.previous !== ''"
                >{{$t('previousPage')}}</div>

                <div
                  @click="getList(item)"
                  :class="{circle: item === currentPage}"
                  class="page"
                  v-for="(item, index) in pages"
                  :key="index"
                  v-if="item <= getGamePagination.pages && item >= 1"
                >{{item}}</div>

                <div
                  @click="getList(getGamePagination.next)"
                  v-if="getGamePagination.next !== ''"
                >{{$t('nextPage')}}</div>
                <div
                  @click="getList(getGamePagination.pages)"
                  v-if="getGamePagination.next !== ''"
                >{{$t('lastPage')}}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import PointsHeader from '@/components/G733/Points_header'
import maskModal from './Modal'
import 'swiper/dist/css/swiper.css'
import Bus from './Bus.js'
import { swiper, swiperSlide } from 'vue-awesome-swiper'
import { setTimeout, clearTimeout } from 'timers'
import { async, Promise } from 'q'
import { constants } from 'fs'
function playSound() {
  var sound = document.getElementById('audio')
  sound.play()
}
export default {
  components: {
    PointsHeader,
    maskModal,
    swiper,
    swiperSlide
  },
  computed: {
    ...mapGetters([
      'getLobby_Vendors',
      'getGameList',
      'getGameTypes',
      'getVendor_balance',
      'getNew_VendorBalance',
      'getVendor_Info',
      'getGamePagination',
      'get_EventsGames',
      'getNewList',
      'getHotList',
      'getOftenList'
    ]),
    pages() {
      const c = this.getGamePagination.current
      const t = this.getGamePagination.pages
      if (c <= 5) {
        return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] //第一种情况
      } else if (c >= t - 4) {
        return [
          t - 9,
          t - 8,
          t - 7,
          t - 6,
          t - 5,
          t - 4,
          t - 3,
          t - 2,
          t - 1,
          t
        ] //第二种情况
      } else {
        return [
          c - 4,
          c - 3,
          c - 2,
          c - 1,
          c,
          c + 1,
          c + 2,
          c + 3,
          c + 4,
          c + 5
        ] //第三种情况
      }
    },
    getBalance() {
      let vendorPoints = this.getNew_VendorBalance
        ? this.getNew_VendorBalance
        : this.getVendor_balance
      return vendorPoints
    }
  },
  props: {
    vendors_type: ''
    // vendorName: ''
  },

  data() {
    return {
      template: window.sessionStorage.getItem('Template'),
      vWorld_balance: window.sessionStorage.getItem('balance'),
      comfirmVisible: false,
      hide: true,
      // gamelist data
      gameId: '',
      // 搜尋遊戲
      searchKey: '',
      timeout: null,
      //deposit data
      vendor_name: '遊戲商',
      vendorId: '',
      depositVal: 0,
      transferPoint: 0,
      depositVisible: false,
      isNormal_deposit: true,
      transferInOut: '',
      vendorNumber: this.$route.params.vendorID,
      vendorName: sessionStorage.getItem('vendor_name'),
      vendorId: this.$route.params.vendorID,
      // 活動進列表
      eventBarID: this.$route.params.id,
      routerVendorID: this.$route.params.vendorID,
      routerVendorName: this.$route.params.vendorName,
      eventOpenGame: this.$route.params.eventOpenGame,

      typeMore: this.$route.params.typeMore,

      swiperOptionHotNew: {
        slidesPerView: 2.1, //一行顯示3個
        slidesPerColumn: 1, //顯示3行
        spaceBetween: 0, //在slide之間設置距離(px)
        slidesPerColumnFill: 'row', //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
        grabCursor: true, // 游標為手掌
        slidesOffsetBefore: 10, //設定slide與左邊框的預設偏移量（單位px）
        slidesOffsetAfter: 30, //設定slide與右邊框的預設偏移量（單位px）

        pagination: {
          el: '.swiper-pagination', //分頁點
          clickable: true
        }
      },
      swiperOptionHotNew_PC: {
        slidesPerView: 6, //一行顯示3個
        slidesPerColumn: 1, //顯示3行
        spaceBetween: 0, //在slide之間設置距離(px)
        slidesPerColumnFill: 'row', //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
        grabCursor: true, // 游標為手掌
        slidesOffsetBefore: 10, //設定slide與左邊框的預設偏移量（單位px）
        slidesOffsetAfter: 30, //設定slide與右邊框的預設偏移量（單位px）

        pagination: {
          el: '.swiper-pagination', //分頁點
          clickable: true
        }
      },
      mobilepc: sessionStorage.getItem('PC_MOBILE'),
      //   分頁
      currentPage: 1
    }
  },

  mounted() {
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    //橫向禁止
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
    // 畫面進入全選
    $('#type0').removeClass('gameListBg')
    $('#type0').addClass('gameListBg_s')
  },
  methods: {
    //   遊戲商LOGO
    vendorLogo(vendor_id) {
      var Vendor = this.getLobby_Vendors.filter(function(ary) {
        return ary.id == vendor_id
      })
      var logo = Vendor[0].image
      return logo
    },

    // 進遊戲
    playGame(list) {
      this.gameId = list.id
      this.gameName = list.name
      this.vendorId = list.vendor_id

      this.$store.dispatch('actionOne_balance', this.vendorId).then(balance => {
        let vendor_balance = parseInt(balance.toString().replace(',', ''), 10)
        if (vendor_balance < 0) {
          this.depositVisible = true
          this.isNormal_deposit = false
          this.vendor_name = list.vendor_name
        } else {
          this.$router.push('/Game?id=' + this.gameId)
          ga('send', 'event', {
            eventCategory: 'GameList',
            eventAction: 'Game_Click',
            eventLabel: this.gameName,
            eventValue: this.gameId
          })

          var sound = document.getElementById('audio')
        }
      })
    },
    // 取單支遊戲商餘額
    getVendorBalance() {
      let vendor_id = this.$route.params.vendorID
      if (vendor_id != '') {
        this.$store.dispatch('actionOne_balance', vendor_id).then(balance => {
          return balance
        })
      } else {
        this.hide = false
        setTimeout(() => {
          $('.GameListMenu').css('height', 50 + 'px')
          $('.GameListItem').css('padding-top', 50 + 'px')
        }, 100)
      }
    },
    // 轉點
    pointTransfer(transferInOut) {
      this.vWorld_balance = window.sessionStorage.getItem('balance')
      this.transferInOut = transferInOut
      this.vendor_name = sessionStorage.getItem('vendor_name')
      this.depositVisible = true
      this.isNormal_deposit = true
      if (this.transferInOut == 2) {
        var transferType = '轉出按鈕'
      } else {
        var transferType = '轉入按鈕'
      }
      ga('send', 'event', {
        eventCategory: 'GameList',
        eventAction: 'transferBtn_Click',
        eventLabel: transferType,
        eventValue: this.transferInOut
      })
    },
    comfirm() {
      this.comfirmVisible = false
      this.$router.push('/Game?id=' + this.gameId)
      ga('send', 'event', {
        eventCategory: 'GameList',
        eventAction: 'Game_Click',
        eventLabel: this.gameName,
        eventValue: this.gameId
      })

      var sound = document.getElementById('audio')
      sound.play()
    },
    cancel() {
      this.comfirmVisible = false
      var sound = document.getElementById('audio')
      sound.play()
    },
    close() {
      this.depositVisible = false
    },

    // 遊戲列表 //
    getGamelist(data) {
      $('.removebg').removeClass('gameListBg_s')
      $('.removebg').addClass('gameListBg')
      $('#type' + data).addClass('gameListBg_s')

      this.searchKey = ''
      if (data == undefined || data == 0) {
        sessionStorage.setItem('type_id', '')
      }
      sessionStorage.setItem('personal', '')

      let listData = {
        vendor_id:
          this.$route.params.vendorID == 0 ? '' : this.$route.params.vendorID,
        type_id: sessionStorage.getItem('type_id'),
        key: '',
        page: 1,
        per_page: 100
      }

      if (data) {
        // have type
        sessionStorage.setItem('type_id', data)
        listData.type_id = data
      }

      this.$store.dispatch('actionGamelist', listData)
    },

    /// 餘額不足轉點 //
    inputVal() {
      this.depositVal = parseInt(this.depositVal, 10)
      let vPoints = parseInt(
        this.vWorld_balance.toString().replace(',', ''),
        10
      )
      if (this.depositVal >= vPoints) {
        this.depositVal = vPoints
      } else if (!this.depositVal) {
        this.depositVal = 0
      }
    },
    //轉出input
    inputtransferVal() {
      this.transferPoint = parseInt(this.transferPoint, 10)
      let getPoints = parseInt(this.getBalance.toString().replace(',', ''), 10)
      if (this.transferPoint >= getPoints) {
        this.transferPoint = getPoints
      } else if (!this.transferPoint) {
        this.transferPoint = 0
      }
    },
    // 遊戲餘額不足，點數轉入
    deposit() {
      let data = {}
      data['isDeposit'] = true
      data['vendor_id'] = this.vendorId
      data['point'] = this.depositVal
      data['game_id'] = this.gameId
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    },
    // 一般轉點
    normal_deposit() {
      let data = {}
      data['isDeposit'] = false
      //   data['vendor_id'] = sessionStorage.getItem('vendor_id')
      data['vendor_id'] = this.$route.params.vendorID
      data['point'] = this.depositVal
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    },
    //點數轉出
    transferOut() {
      let data = {}
      //   data['vendor_id'] = sessionStorage.getItem('vendor_id')
      data['vendor_id'] = this.$route.params.vendorID
      data['point'] = this.transferPoint
      this.$store.dispatch('vendorToBalance', data)
      this.depositVisible = false
      this.vWorld_balance = window.sessionStorage.getItem('balance')
    },

    openapp() {
      // 判斷IOS ANDROID
      let vm = this
      $(function() {
        var u = navigator.userAgent,
          app = navigator.appVersion
        var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
        var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/)
        if (isAndroid) {
          vm.Android()
        }
      })
    },
    Android() {
      this.GOApp(this.getVendor_Info.android_open)
    },
    GOApp(scheme_url, timeout = 3000) {
      let startTime = Date.now()
    },
    // 分頁
    getList(page) {
      if (this.eventOpenGame == 1) {
        this.currentPage = page

        let listData = {
          detail_id: this.eventBarID,
          page: page,
          per_page: 40
        }
        this.$store.dispatch('action_EventGames', listData)
      } else {
        this.currentPage = page

        if (this.$route.params.vendorID == undefined) {
          var str = ''
        } else {
          var str = this.$route.params.vendorID
        }

        let listData = {
          vendor_id: str ? str : '',
          type_id: this.gametypeid ? this.gametypeid : '',
          page: page,
          per_page: 40
        }

        this.$store.dispatch('action_myGames', listData)
      }
    }
  },
  created() {
    this.depositVal = Math.round(
      parseInt(this.vWorld_balance.toString().replace(',', ''), 10) / 2
    )
    // 從活動進入的遊戲
    if (this.eventOpenGame == 1) {
      let listData = {
        detail_id: this.eventBarID,
        page: 1,
        per_page: 30
      }
      sessionStorage.setItem('vendor_id', this.routerVendorID)
      this.$store.dispatch('action_EventGames', listData)
    } else {
      this.getGamelist()
      // 執行後跑上方getGamelist FUNCTION
    }
    // 類型(預設1)1:遊戲列表，2:轉點列表
    let data = {
      type: 1,
      isGetBalance: false
    }
    this.$store.dispatch('actionVendors', data)

    if (sessionStorage.getItem('PC_MOBILE') == 'MOBILE') {
      this.getVendorBalance()
    }
    this.$store.dispatch('action_gameTypes', this.$route.params.vendorID)
    this.$store.dispatch('action_favorite_Length', this.$route.params.vendorID)

    this.getList(1)
  }
}
</script>
<style scoped>
</style>