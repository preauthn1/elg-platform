<template>
  <div>
    <div class="gameType" v-if="getHotList.length > 0">
      <div class="gameTypeTitle clearfix">
        <div class="gameTypeLeft float-left title">{{ $t("hotGame") }}</div>
      </div>
      <div class="gameItem">
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
                v-if="item.event_bet || item.event_win"
              />
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>

              <div class="gameListConL">
                <img :src="item.image" class="gamePic" :class="item.disable==1?'disGray':''" />
              </div>
            </div>
            <div class="gameName">{{ item.name }}</div>
          </swiper-slide>
          <div class="swiper-button-prev" slot="button-prev"></div>
          <div class="swiper-button-next" slot="button-next"></div>
        </swiper>
      </div>
    </div>
    <div class="gameType" v-if="getNewList.length > 0">
      <div class="gameTypeTitle clearfix">
        <div class="gameTypeLeft float-left title">{{ $t("newGame") }}</div>
      </div>
      <div class="gameItem">
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
                v-if="item.event_bet || item.event_win"
              />
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>
              <div class="gameListConL">
                <img :src="item.image" class="gamePic" :class="item.disable==1?'disGray':''" />
              </div>
            </div>
            <div class="gameName">{{ item.name }}</div>
          </swiper-slide>
          <div class="swiper-button-prev" slot="button-prev"></div>
          <div class="swiper-button-next" slot="button-next"></div>
        </swiper>
      </div>
    </div>
    <div class="gameType" v-if="loging && getOftenList.length > 0">
      <div class="gameTypeTitle clearfix">
        <div class="gameTypeLeft float-left title">{{ $t("often") }}</div>
      </div>
      <div class="gameItem">
        <swiper :options="swiperOptionNormal">
          <swiper-slide class="swiperPic" v-for="(item, index) in getOftenList" :key="index">
            <div class="gamePicAll" @click="item.disable==1?'':playGame(item)">
              <img src="../../../static/img/C711_PC/gameItemBg.png" class="GameItemB" />
              <img
                src="../../../static/img/C711_PC/lock.png"
                class="lockBtn"
                v-if="item.disable == 1"
              />
              <img
                src="../../../static/img/C711_PC/Gift.png"
                class="Gift"
                v-if="item.event_bet || item.event_win"
              />
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>
              <div class="gameListCon">
                <img :src="item.image" class="gamePic" :class="item.disable==1?'disGray':''" />
              </div>
            </div>
            <div class="gameName">{{ item.name }}</div>
          </swiper-slide>
          <div class="swiper-button-prev" slot="button-prev"></div>
          <div class="swiper-button-next" slot="button-next"></div>
        </swiper>
      </div>
    </div>
    <div class="gameType" v-if="loging">
      <div class="gameTypeTitle clearfix">
        <div class="gameTypeLeft float-left title">{{ $t("AllGames") }}</div>
        <div class="gameTypeRight float-right" style="cursor:pointer;">
          <span class="seeAll_btn" @click="checkAllGames()">{{$t("more")}}</span>
        </div>
      </div>
      <div class="gameItem">
        <swiper :options="swiperOptionNormal">
          <swiper-slide class="swiperPic" v-for="(item, index) in getGameList" :key="index">
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
                v-if="item.event_bet || item.event_win"
              />
              <!--  -->
              <div class="logoframe">
                <img class="gameLogo" :src="vendorLogo(item.vendor_id)" />
                <img src="../../../static/img/C711_PC/user@example.invalid" />
              </div>
              <div class="gameListCon">
                <img :src="item.image" class="gamePic" :class="item.disable==1?'disGray':''" />
              </div>
            </div>
            <div class="gameName">{{ item.name }}</div>
          </swiper-slide>
          <div class="swiper-button-prev" slot="button-prev"></div>
          <div class="swiper-button-next" slot="button-next"></div>
        </swiper>
      </div>
    </div>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LobbyHeader from '@/components/C711_PC/Lobby_header'
import LobbyBanner from '@/components/C711_PC/Lobby_banner'
import 'swiper/dist/css/swiper.css'
import { swiper, swiperSlide } from 'vue-awesome-swiper'

export default {
  components: {
    LobbyHeader,
    LobbyBanner,
    swiper,
    swiperSlide
  },
  computed: {
    ...mapGetters([
      'getLobby_Vendors',
      'getGameList',
      'getNewList',
      'getHotList',
      'getOftenList'
    ])
  },
  data() {
    return {
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
      swiperOptionNormal: {
        slidesPerView: 8, //一行顯示3個
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
      //
      loging: sessionStorage.getItem('token')
    }
  },
  watch: {
    getLobby_Vendors() {
      let idAry = []
      var vendorAry = this.getLobby_Vendors.filter(function(ary) {
        return ary.need_login == 0 && ary.status == 1
      })
      vendorAry.forEach(element => {
        idAry.push(element.id)
      })
      // 全部遊戲 & 最熱 & 最新
      let listData = {
        vendor_ids: idAry,
        page: 1,
        per_page: 150
      }
      this.$store.dispatch('action_myGames', listData)
      // 最常
      let oftenData = {
        personal: 2,
        page: 1,
        per_page: 150
      }
      this.$store.dispatch('action_oftenList', oftenData)
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

      this.$router.push('/Game?id=' + gameId)
    },
    checkAllGames() {
      let sessionVendors = [] // 季session
      let idAry = [] // 傳路由 空動作為了gamelist能watch
      var vendorAry = this.getLobby_Vendors.filter(function(ary) {
        return ary.need_login == 0 && ary.status == 1
      })
      vendorAry.forEach(element => {
        sessionVendors.push(element)
        idAry.push(element.id)
      })
      sessionStorage.setItem('vendors_type', JSON.stringify(sessionVendors))
      let idStr = idAry.join(',')
      this.$router.push('/GameList/' + idStr)
    },
    clearSearchTitle() {
      sessionStorage.setItem('gametypeName', '')
      sessionStorage.setItem('gametypeid', '')
    }
  },
  created() {
    this.clearSearchTitle()

    // 假資料列表
    if (!this.loging) {
      let listData = []
      this.$store.dispatch('action_myGames', listData)
    }
  }
}
</script>
