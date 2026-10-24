<template>
  <div id="eventRightGames">
    <div class="gameTypeTitle clearfix">
      <div class="gameTypeLeft float-left">
        <div v-if="eventOpenGame==1" class="title">{{routerVendorName}}</div>
        <div v-else>
          <span class="title" v-if="vendortypeName=='' && gametypeName==''">{{$t('AllGames')}}</span>
          <span class="title" v-if="gametypeName!==''">{{gametypeName}}</span>
          <span class="title" v-if="vendortypeName.length !== 0 && gametypeName!==''">(</span>
          <span class="title" v-for="(vendor,i) in vendortypeName" :key="i">
            <span>{{vendor}}</span>
            <span v-if="i+1 < vendortypeName.length">、</span>
          </span>
          <span class="title" v-if="vendortypeName.length !== 0 && gametypeName!==''">)</span>
        </div>
      </div>
    </div>

    <div class="gameType">
      <div class="gameItem" v-if="getHotList.length > 0">
        <swiper :options="swiperOptionHotNew">
          <swiper-slide class="swiperPic" v-for="(item, index) in getHotList" :key="index">
            <div class="gamePicAll" @click="item.disable==1?'':playGame(item)">
              <img src="../../../static/img/C711_PC/user@example.invalid" class="GameItemB" />
              <img src="../../../static/img/C711_PC/Hot.png" class="NewHotBtn" />
              <img
                src="../../../static/img/C711_PC/lock.png"
                class="lockBtn"
                v-if="item.disable == 1"
              />
              <img
                src="../../../static/img/C711_PC/Gift.png"
                class="Gift"
                v-if="item.event_bet||item.event_win"
              />
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>
              <div class="gameListConL">
                <img
                  :src="item.image"
                  class="gamePic"
                  style="height:auto"
                  :class="item.disable==1?'disGray':''"
                />
              </div>
            </div>
            <div class="gameName">{{item.name}}</div>
          </swiper-slide>
          <div class="swiper-button-prev" slot="button-prev"></div>
          <div class="swiper-button-next" slot="button-next"></div>
        </swiper>
      </div>
    </div>
    <div class="gameType">
      <div class="gameItem" v-if="getNewList.length > 0">
        <swiper :options="swiperOptionHotNew">
          <swiper-slide class="swiperPic" v-for="(item, index) in getNewList" :key="index">
            <div class="gamePicAll" @click="item.disable==1?'':playGame(item)">
              <img src="../../../static/img/C711_PC/user@example.invalid" class="GameItemB" />
              <img src="../../../static/img/C711_PC/New.png" class="NewHotBtn" />
              <img
                src="../../../static/img/C711_PC/lock.png"
                class="lockBtn"
                v-if="item.disable == 1"
              />
              <img
                src="../../../static/img/C711_PC/Gift.png"
                class="Gift"
                v-if="item.event_bet||item.event_win"
              />
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>
              <div class="gameListConL">
                <img
                  :src="item.image"
                  class="gamePic"
                  style="height:auto"
                  :class="item.disable==1?'disGray':''"
                />
              </div>
            </div>
            <div class="gameName">{{item.name}}</div>
          </swiper-slide>
          <div class="swiper-button-prev" slot="button-prev"></div>
          <div class="swiper-button-next" slot="button-next"></div>
        </swiper>
      </div>
    </div>
    <div class="gameType">
      <div class="gameItem">
        <div class="allgames">
          <div v-for="(item, index) in getGameList" :key="index">
            <div class="gamePicAll" @click="item.disable==1?'':playGame(item)">
              <img src="../../../static/img/C711_PC/gameItemBg.png" class="GameItemB" />
              <img
                src="../../../static/img/C711_PC/lock.png"
                class="lockBtnS"
                v-if="item.disable == 1"
              />
              <img
                src="../../../static/img/C711_PC/Gift.png"
                class="Gift"
                v-if="item.event_bet||item.event_win"
              />
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>
              <div class="gameListCon">
                <img :src="item.image" class="gamePic" :class="item.disable==1?'disGray':''" />
              </div>
            </div>
            <div class="gameName">{{item.name}}</div>
          </div>
          <div class="noGame" v-if="getGameList==''">{{$t('noMatchingGames')}}</div>
        </div>
      </div>
    </div>

    <!-- 一般頁數 -->
    <div>
      <div
        class="Pagination"
        v-if="getGamePagination.length !== 0 && getGamePagination.total !== 0"
      >
        <div @click="getList(1)" v-if="getGamePagination.previous !== ''">{{$t('firstPage')}}</div>
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
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import Bus from './Bus.js'
import 'swiper/dist/css/swiper.css'
import { swiper, swiperSlide } from 'vue-awesome-swiper'
import { setInterval } from 'timers'
export default {
  components: {
    swiper,
    swiperSlide
  },
  data() {
    return {
      currentPage: 1,
      swiperOptionHotNew: {
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
        },
        navigation: {
          nextEl: '.swiper-button-next',
          prevEl: '.swiper-button-prev'
        }
      },
      // 用$on事件来接收 header gametype 参数
      gametypeid: '',
      gametypeName: '',
      // 用props 来接收 lobby 選擇器参数
      vendortypeid: [], //api傳值vendor陣列
      vendortypeName: [], // title vendor name,
      i: 1,
      //   t: '',
      // 活動進列表
      eventBarID: this.$route.params.id,
      routerVendorID: this.$route.params.vendorID,
      routerVendorName: this.$route.params.vendorName,
      eventOpenGame: this.$route.params.eventOpenGame
    }
  },
  computed: {
    ...mapGetters([
      'getLobby_Vendors',
      'getGameList',
      'getGamePagination',
      'getNewList',
      'getHotList',
      'get_EventsGames',
      'getEventGamePagination'
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
  props: {
    vendors_type: '',
    vendorName: ''
  },

  watch: {
    $route() {
      if (this.$route.params.vendorID == undefined) {
        var str = ''
      } else {
        var str = this.$route.params.vendorID
      }

      if (str == '') {
        this.$store.commit('clearGamelist')
        this.vendortypeName = []
      } else {
        // 2.0v
        let sessionVendorAry = JSON.parse(
          sessionStorage.getItem('vendors_type')
        )
        let vendor_ids = [] //api傳值vendor陣列
        this.vendortypeName = [] // 列表頁選取title
        sessionVendorAry.forEach(element => {
          vendor_ids.push(element.id)
          this.vendortypeName.push(element.name)
        })
        let listData = {
          vendor_ids: vendor_ids,
          type_id: this.gametypeid,
          page: 1,
          per_page: 40
        }
        this.vendortypeid = vendor_ids
        this.$store.dispatch('action_myGames', listData)
      }
    },
    gametypeid() {
      let str = this.$route.params.vendorID

      if (str) {
        let listData = {
          vendor_ids: this.vendortypeid,
          type_id: this.gametypeid,
          page: 1,
          per_page: 20
        }
        this.$store.dispatch('action_myGames', listData)
      } else {
        this.$store.commit('clearGamelist')
      }
    }
  },
  mounted() {
    //   活動進入
    if (this.eventOpenGame == 1) {
      $('#leftMenu').css('display', 'none')
      $('#lobbyAppGame').css('display', 'none')
      $('#eventRightGames').css('width', 100 + '%')
      $('.rightGame').css('width', 100 + '%')
    } else {
      $('#leftMenu').css('display', 'block')
      $('#lobbyAppGame').css('display', 'block')
      $('#eventRightGames').css('width', 100 + '%')
    }
    // banner進入
    if (this.$route.params.banner == 1) {
      $('#leftMenu').css('display', 'none')
      $('#lobbyAppGame').css('display', 'none')
      $('#eventRightGames').css('width', 100 + '%')
      $('.rightGame').css('width', 100 + '%')
    }
  },

  methods: {
    vendorLogo(vendor_id) {
      var Vendor = this.getLobby_Vendors.filter(function(ary) {
        return ary.id == vendor_id
      })
      var logo = Vendor[0].image
      return logo
    },

    playGame(list) {
      let gameId = list.id
      let vendorID = list.vendor_id
      sessionStorage.setItem('vendor_id', list.vendor_id)
      sessionStorage.setItem('vendor_name', list.vendor_name)
      this.$router.push('/Game?id=' + gameId + '&vendorID=' + vendorID)
    },
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
    },

    getSelectTitle() {
      // vendor name
      let sessionVendorAry = JSON.parse(sessionStorage.getItem('vendors_type'))

      sessionVendorAry.forEach(element => {
        this.vendortypeName.push(element.name)
      })
      // gametype
      this.gametypeid = sessionStorage.getItem('gametypeid')
      this.gametypeName = sessionStorage.getItem('gametypeName')
    }
  },
  created() {
    var vm = this
    Bus.$on('gametype', data => {
      // 用$on事件来接收参数 lobby_header的 gametype

      vm.gametypeid = data.id
      vm.gametypeName = data.name
      sessionStorage.setItem('gametypeid', data.id)
      sessionStorage.setItem('gametypeName', data.name)
    })
    Bus.$on('vendorID', data => {})

    this.getSelectTitle()

    this.getList(1)
  }
}
</script>