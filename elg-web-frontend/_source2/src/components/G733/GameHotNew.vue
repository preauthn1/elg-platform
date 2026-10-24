<template>
  <div id="G733">
    <div class="points_Header">
      <LoginHeader></LoginHeader>
    </div>
    <div class="single_block">
      <div class="titleHotNew">{{HotNewTitle}}</div>
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2 GameNoB">
          <!-- 熱門遊戲 -->
          <div class="gameList" v-if="typeMore=='hot'">
            <div class="vendorName"></div>
            <div class="vendorGame clearfix">
              <div
                class="float-left oneGameAll"
                v-for="(list,i) in getHotList"
                :key="i"
                @click="playGame(list) "
              >
                <div class="oneGame">
                  <img src="../../assets/img/G733/smallImg.png" class="oneGameBG" />
                  <!--  -->
                  <img
                    src="../../assets/img/G733/lock.png"
                    class="lockBtnS"
                    v-if="list.disable == 1"
                  />
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
          </div>
          <!-- 最新遊戲 -->
          <div class="gameList" v-if="typeMore=='new'">
            <div class="vendorName"></div>
            <div class="vendorGame clearfix">
              <div
                class="float-left oneGameAll"
                v-for="(list,i) in getNewList"
                :key="i"
                @click="playGame(list) "
              >
                <div class="oneGame">
                  <img src="../../assets/img/G733/smallImg.png" class="oneGameBG" />
                  <img
                    src="../../assets/img/G733/lock.png"
                    class="lockBtnS"
                    v-if="list.disable == 1"
                  />
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
          </div>
          <!-- 最常遊玩 -->
          <div class="gameList" v-if="typeMore=='often'">
            <div class="vendorName"></div>
            <div class="vendorGame clearfix">
              <div
                class="float-left oneGameAll"
                v-for="(list,i) in getOftenList"
                :key="i"
                @click="playGame(list) "
              >
                <div class="oneGame">
                  <img src="../../assets/img/G733/smallImg.png" class="oneGameBG" />
                  <img
                    src="../../assets/img/G733/lock.png"
                    class="lockBtnS"
                    v-if="list.disable == 1"
                  />
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
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LoginHeader from '@/components/G733/Login_header'
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
    LoginHeader,
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
    }
  },

  data() {
    return {
      gameId: '',
      // 搜尋遊戲
      searchKey: '',
      timeout: null,
      //deposit data
      vendor_name: '遊戲商',
      vendorId: '',
      depositVisible: false,
      isNormal_deposit: true,
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

      //   分頁
      currentPage: 1,
      HotNewTitle: ''
    }
  },

  mounted() {
    //   TITLE
    if (this.typeMore == 'hot') {
      // PC使用
      this.HotNewTitle = this.$t('hotGame')
      //   手機使用
      $('.loginbarTitle').html(this.$t('hotGame'))
    } else if (this.typeMore == 'new') {
      // PC使用
      this.HotNewTitle = this.$t('newGame')
      //   手機使用
      $('.loginbarTitle').html(this.$t('newGame'))
    } else if (this.typeMore == 'often') {
      // PC使用
      this.HotNewTitle = this.$t('often')
      //   手機使用
      $('.loginbarTitle').html(this.$t('often'))
    }
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

        if (str) {
          this.vendortypeid = str.split(',')
          let listData = {
            vendor_ids: this.vendortypeid,
            type_id: this.gametypeid,
            page: page,
            per_page: 40
          }

          this.$store.dispatch('action_myGames', listData)
        } else if (str == '') {
          this.$store.commit('clearGamelist')
        }
      }
    }
  },
  created() {
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

    this.getList(1)
  }
}
</script>
<style scoped>
</style>