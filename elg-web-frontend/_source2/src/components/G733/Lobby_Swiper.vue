<template>
  <div>
    <!-- 九宮格開始 -->

    <!-- 熱門遊戲 -->
    <div class="typeAll" v-if="getHotList.length > 0">
      <div class="typeName clearfix">
        <div class="typeTitle float-left">
          <img src="../../assets/img/G733/goldline.png" />
          <span>{{ $t("hotGame") }}</span>
        </div>
        <a :href="/GameHotNew/ +'typeMore' +'/' +'hot' ">
          <div class="more float-right">{{$t("more")}}</div>
        </a>
      </div>
      <div class="HotNewGame">
        <swiper :options="mobilepc=='PC'?swiperOptionHotNew_PC:swiperOptionHotNew">
          <swiper-slide class="swiperPic" v-for="(list,i) in getHotList" :key="i">
            <div class="gamePicAll" @click="playGame(list)">
              <img src="../../assets/img/G733/bigImg.png" class="GameItemB" />

              <img src="../../assets/img/G733/HotBtnL.png" class="NewHotBtn" />
              <img src="../../assets/img/G733/lock.png" class="lockBtnS" v-if="list.disable == 1" />
              <!-- v-if="item.disable == 1" -->
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
      <div class="bottomBorder"></div>
    </div>
    <!-- 最新遊戲 -->
    <div class="typeAll" v-if="getNewList.length > 0">
      <div class="typeName clearfix">
        <div class="typeTitle float-left">
          <img src="../../assets/img/G733/goldline.png" />
          <span>{{ $t("newGame") }}</span>
        </div>
        <a :href="/GameHotNew/ +'typeMore' +'/' +'new' ">
          <div class="more float-right">{{$t("more")}}</div>
        </a>
      </div>
      <div class="HotNewGame">
        <swiper :options="mobilepc=='PC'?swiperOptionHotNew_PC:swiperOptionHotNew">
          <swiper-slide class="swiperPic" v-for="(list,i) in getNewList" :key="i">
            <div class="gamePicAll" @click="playGame(list)">
              <img src="../../assets/img/G733/bigImg.png" class="GameItemB" />

              <img src="../../assets/img/G733/HotBtnL.png" class="NewHotBtn" />
              <img src="../../assets/img/G733/lock.png" class="lockBtnS" v-if="list.disable == 1" />
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
      <div class="bottomBorder"></div>
    </div>
    <!-- 最常遊玩 -->
    <div class="typeAll" v-if="loging && getOftenList.length > 0">
      <div class="typeName clearfix">
        <div class="typeTitle float-left">
          <img src="../../assets/img/G733/goldline.png" />
          <span>{{ $t("often") }}</span>
        </div>
        <a :href="/GameHotNew/ +'typeMore' +'/' +'often' ">
          <div class="more float-right">{{$t("more")}}</div>
        </a>
      </div>
      <div class="HotNewGame">
        <swiper :options="mobilepc=='PC'?swiperOptionHotNew_PC:swiperOptionHotNew">
          <swiper-slide class="swiperPic" v-for="(list,i) in getOftenList" :key="i">
            <div class="gamePicAll" @click="playGame(list)">
              <img src="../../assets/img/G733/bigImg.png" class="GameItemB" />

              <img src="../../assets/img/G733/HotBtnL.png" class="NewHotBtn" />
              <img src="../../assets/img/G733/lock.png" class="lockBtnS" v-if="list.disable == 1" />
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
    </div>
    <!-- 全部遊戲 -->
    <div class="typeAll" v-if="loging">
      <div class="typeName clearfix">
        <div class="typeTitle float-left">
          <img src="../../assets/img/G733/goldline.png" />
          <span>{{ $t("AllGames") }}</span>
        </div>
        <a href="/GameList">
          <div class="more float-right">{{$t("more")}}</div>
        </a>
      </div>
      <div class="HotNewGame">
        <swiper :options="mobilepc=='PC'?swiperOptionHotNew_PC:swiperOptionHotNew">
          <swiper-slide class="swiperPic" v-for="(list,i) in getGameList" :key="i">
            <div class="gamePicAll" @click="playGame(list)">
              <img src="../../assets/img/G733/bigImg.png" class="GameItemB" />

              <img src="../../assets/img/G733/HotBtnL.png" class="NewHotBtn" />
              <img src="../../assets/img/G733/lock.png" class="lockBtnS" v-if="list.disable == 1" />
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
    </div>
    <!-- 九宮格結束-->
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'

import maskModal from './Modal'
import 'swiper/dist/css/swiper.css'
import { swiper, swiperSlide } from 'vue-awesome-swiper'
export default {
  components: {
    maskModal,
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
        slidesPerView: 2.5, //一行顯示3個
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
        }
      },
      //
      loging: sessionStorage.getItem('token'),
      getEventWin: [],
      mobilepc: sessionStorage.getItem('PC_MOBILE'),
      eventWin: ''
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
      this.$router.push('/gameList?vds=' + idStr)
    },
    clearSearchTitle() {
      //   sessionStorage.setItem('vendors_type', JSON.stringify([]))
      sessionStorage.setItem('gametypeName', '')
      sessionStorage.setItem('gametypeid', '')
    }
  },
  mounted() {},

  created() {
    //   類型(預設1)1:遊戲列表，2:轉點列表
    let data = {
      type: 1,
      isGetBalance: false
    }
    this.$store.dispatch('actionVendors', data)
    // 假資料列表

    if (!this.loging) {
      let listData = []
      this.$store.dispatch('action_myGames', listData)
    }
  }
}
</script>