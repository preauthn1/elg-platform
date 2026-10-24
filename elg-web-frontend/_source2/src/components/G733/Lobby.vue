<template>
  <div id="G733">
    <div class="mobileHeader">
      <PointsHeader></PointsHeader>
    </div>
    <div class="pcHeader">
      <LobbyPCHeader></LobbyPCHeader>
    </div>

    <img src="../../assets/img/G733/bg.png" alt class="lobbyBg" />

    <div class="bannerLobby" style="z-index:99;" v-if="$route.meta.banner">
      <Banner></Banner>
    </div>
    <!-- LoginMain -->
    <div class="single_block lobbyMain">
      <div class="LoginMain">
        <div class="mainNoB zindex2 lobbyNoB">
          <div class="unionBorder" v-if="$route.meta.unionBorder ">
            <a href="/Lobby">
              <img :src="pathname=='/Lobby'?Union:Union1" alt />
            </a>
            <a href="/LobbyList">
              <img :src="pathname=='/LobbyList'?Union_line:Union_line1" alt />
            </a>
          </div>

          <div class="Lobby_main clearfix">
            <div class="pcleft">
              <!-- 遊戲商MENU Lobby/LobbyList/-->
              <div class="gamerList" v-if="$route.meta.pc_gamer">
                <div v-for="(vendor, i) in getLobby_Vendors" :key="i">
                  <a @click="gameList(vendor);">
                    <div class="gamerOne" v-if="vendor.status == 1 &&vendor.need_login != 1">
                      <img class="vendorLogo" :src="vendor.image" />
                      <span>{{ vendor.name }}</span>
                    </div>
                  </a>
                </div>
              </div>
              <!-- 個人資料 平台轉入轉出 紀錄 MENU -->
              <div class="gamerList" v-else>
                <a href="/Setting">
                  <div class="gamerOne ListTitle">
                    <span>{{$t('setting')}}</span>
                  </div>
                </a>
                <a href="/Points">
                  <div class="gamerOne ListTitle">
                    <span>{{$t('platformTransfer')}}</span>
                  </div>
                </a>
                <a href="/RecordList">
                  <div class="gamerOne ListTitle">
                    <span>{{$t('recordInquiry')}}</span>
                  </div>
                </a>
              </div>
            </div>
            <div class="pcright">
              <div class="marquee_Pos pcMarquee">
                <Marquee></Marquee>
              </div>
              <!-- PC版 app遊戲商 -->
              <div id="lobbyAppGame" v-if="$route.meta.pcApp">
                <div v-if="isAppTitle_show" class="gameTypeTitle_lobby">
                  <div class="title_lobby">{{$t('appgamepublisher')}}</div>
                </div>
                <swiper :options="swiperOptionQR">
                  <swiper-slide
                    class="swiperPic_lobby"
                    v-for="(vendor, i) in getLobby_Vendors"
                    :key="i"
                    v-if=" vendor.status == 1 &&vendor.need_login == 1"
                  >
                    <div class="gamePicAll_lobby" @click="gamePop(vendor)">
                      <img src="../../assets/img/G733/bigImg.png" class="GameItemB_lobby" />
                      <div class="gameListConL_lobby">
                        <img :src="vendor.image" class="gamePic_lobby" />
                      </div>
                    </div>
                    <div class="gameName_lobby">{{ vendor.name }}</div>
                  </swiper-slide>
                </swiper>
                <div>&nbsp;</div>
              </div>
              <router-view :vendors_type="vendors_type" :vendorName="vendorName"></router-view>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- QR CODE -->

    <div class="mask" v-if="gameAppQR">
      <div class="Popups_ModalQR">
        <div class="PopupsTitle">
          {{titleQR}}
          <img
            @click="gameAppQR = false"
            src="../../assets/img/G733/modalClose.png"
            class="modalClose"
          />
        </div>
        <div class="PopupsConAll">
          <div class="PopupsQR">
            <div id="qrcode" ref="qrcode" class="qrcodePic"></div>
          </div>

          <div class="PopupsBtnQR" style="font-size:13px;padding-top:10px;">
            <div class>*{{$t('scanQRcodeDownload')}}</div>
            <div>*{{$t('afterSigningInWithPhone')}}</div>
          </div>
        </div>
      </div>
    </div>
    <!-- 鎖分活動彈窗 -->

    <div class="popUp_block" style="overflow-y:scroll;" v-if="get_checkLoginEvent">
      <div class="wrap">
        <div class="single_block">
          <div class="case_wrapIframe">
            <div>
              <div class="title">
                {{ $t("eventsContent") }}
                <img
                  @click="modeModal()"
                  class="close_icon"
                  src="../../../static/img/C711_PC/Group5301.png"
                />
              </div>
              <div class="form">
                <div class="orangeTitle" v-if="get_checkEvent.start_time&&get_checkEvent.end_time">
                  <div class="formTitle">{{$t('duringTheEvent')}}:</div>
                  {{get_checkEvent.start_time.substring(0, 16)}} ~ {{get_checkEvent.end_time.substring(0, 16)}}
                </div>
                <div class="orangeTitle">
                  <div class="formTitle">{{$t('participateQualification')}}:</div>
                  <div>1.{{$t('newMemberCreated')}}</div>
                  <div>2.{{$t('existingMembers')}}</div>
                  <div class="formTitle">{{$t('eventRules')}}:</div>
                  {{$t('youWillReceive')}}{{get_checkEvent.point}}{{$t('point')}}，{{$t('afterYouAgree')}}
                  <br />
                  <span>{{$t('theseAreTheCertain')}}</span>
                  <br />
                  1.{{$t('certainGamesAre')}}
                  <br />
                  2.{{$t('cannotOpenWithdrawal')}}
                  <br />
                  3.{{$t('connotJoinOrDelete')}}
                </div>
                <div class="orangeTitle">
                  <div class="formTitle">{{$t('youCanUnlock')}}</div>
                  <div>1.{{$t('balanceIsLesser')}}</div>
                  <div>2.{{$t('balanceIsMoreThen')}}{{get_checkEvent.point*get_checkEvent.times}}{{$t('point')}} {{$t('canKeepAll')}}</div>
                </div>
                <div class="orangeTitle">{{$t('youCanClickTheUnlock')}}</div>
                <div class="redFont">* {{$t('attentionIfYou')}}</div>
                <div class="orangeTitle">
                  {{$t('unlockButtonSign')}}&nbsp;
                  <img
                    src="../../assets/img/G733/lockBTN.png"
                    style="width:70%; margin-left:20px;"
                  />
                </div>
              </div>
              <div class="footer clearfix" style="width:90%;margin:auto;" v-if="EventMode==0">
                <div class="silverBorder btnBorder_all fixedBtn float-left">
                  <button @click="joinEventMode()" class="redBtn btn_all">{{$t('join')}}</button>
                </div>
                <div class="silverBorder btnBorder_all fixedBtn float-right">
                  <button @click="modeModal()" class="grayBtn btn_all">{{$t('notJoin')}}</button>
                </div>
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
import LobbyPCHeader from '@/components/G733/Lobby_PCheader'
import Banner from '@/components/G733/Banner'
import Marquee from '@/components/G733/Marquee'
import maskModal from './Modal'
import QRCode from 'qrcodejs2'
import 'swiper/dist/css/swiper.css'
import { swiper, swiperSlide } from 'vue-awesome-swiper'
export default {
  components: {
    PointsHeader,
    LobbyPCHeader,
    Banner,
    maskModal,
    swiper,
    swiperSlide,
    Marquee
  },
  computed: {
    ...mapGetters([
      'getLobby_Vendors',
      'getGameList',
      'getNewList',
      'getHotList',
      'getOftenList',
      'get_checkLoginEvent',
      'get_checkEvent'
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
      swiperOptionQR: {
        slidesPerView: 7, //一行顯示3個
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

      eventWin: '',
      pathname: location.pathname,
      //   列表九宮格圖片
      Union: require('../../../static/img/G733/Union.png'),
      Union1: require('../../../static/img/G733/Union1.png'),
      Union_line: require('../../../static/img/G733/Union_line.png'),
      Union_line1: require('../../../static/img/G733/Union_line1.png'),
      //   字串上路由
      gameAppQR: false,
      vendors_type: [],
      vendorStr: '', // vendorId 字串上路由
      vendorName: [], //
      EventMode: sessionStorage.getItem('eventMode'),
      vendors_type_Lock: false,
      selectAll: null,
      titleQR: '',
      isAppTitle_show: false,
      mobilepc: sessionStorage.getItem('PC_MOBILE')
    }
  },
  watch: {
    getLobby_Vendors(vendors) {
      if (this.$route.params.vendorID == 'undefined') {
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
      } else {
        // 全部遊戲 & 最熱 & 最新
        let listData = {
          vendor_id: this.$route.params.vendorID,
          page: 1,
          per_page: 150
        }
        this.$store.dispatch('action_myGames', listData)
      }
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
    gameList(vendor) {
      // let name = vendor.name //for app餘額不足彈窗遊戲商名稱,gamelist餘額遊戲商點遊戲playgame(list)帶出來
      sessionStorage.setItem('type_id', '')
      sessionStorage.setItem('vendor_id', vendor.id)
      sessionStorage.setItem('vendor_name', vendor.name)
      sessionStorage.setItem('vendor_balance', vendor.balance)
      sessionStorage.setItem('need_login', vendor.need_login)
      sessionStorage.setItem('only_one_game', vendor.only_one_game)
      ga('send', 'event', {
        eventCategory: 'Lobby',
        eventAction: 'GameList_Click',
        eventLabel: vendor.name,
        eventValue: vendor.id
      })
      if (vendor.need_login == 0) {
        // need_login 是否需開app
        if (vendor.only_one_game == 1) {
          this.$store.dispatch('actionOne_balance', vendor.id).then(balance => {
            let vendor_balance = parseInt(
              balance.toString().replace(',', ''),
              10
            )
            if (vendor_balance < 0) {
              this.vendorName = vendor.name
              this.depositVal = Math.round(
                parseInt(this.vWorld_balance.toString().replace(',', ''), 10) /
                  2
              )
              this.depositVisible = true
            } else {
              let listData = {
                vendor_id: vendor.id,
                page: 1,
                per_page: 100
              }
              this.$store.dispatch('action_myGames', listData)
              this.$router.push('/GameList/' + vendor.id)
            }
          })
        } else {
          // 進列表
          let listData = {
            vendor_id: vendor.id,
            type_id: '',
            key: '',
            page: 1,
            per_page: 100
          }

          this.$store.dispatch('actionGamelist', listData)
          this.show = false
          this.$store.dispatch('actionOne_balance', vendor.id)
          this.$router.push('/GameList/' + vendor.id)
        }
      } else if (vendor.need_login == 1) {
        this.$router.push(
          '/GameApp?vendor=' + vendor.name + '&vendorID=' + vendor.id
        )
        // ?vendor= for gameApp title //
      }
    },
    // 參加鎖分活動
    joinEventMode() {
      this.$store.dispatch('actionJoinLoginEvent')
      this.$store.commit('HIDE_JOIN_EVENT')
    },
    modeModal() {
      this.$store.commit('HIDE_JOIN_EVENT')
    },
    // QR CODE 彈窗
    gamePop(vendor) {
      let token = [REDACTED]'token')
      let locationURL = location.host
      this.gameAppQR = true
      this.titleQR = vendor.name
      // 二维码内容,一般是由后台返回的跳转链接,这里是写死的一个链接
      this.qrcode =
        locationURL +
        '/QRcodeOpen' +
        '/' +
        vendor.id +
        '/' +
        vendor.name +
        '/' +
        token

      // 使用$nextTick确保数据渲染
      this.$nextTick(() => {
        this.crateQrcode()
      })
    },
    // 动态生成二维码
    crateQrcode() {
      let qr = new QRCode('qrcode', {
        width: 200,
        height: 200, // 高度
        text: this.qrcode // 二维码内容或轉跳網址
        // render: 'canvas' // 设置渲染方式（有两种方式 table和canvas，默认是canvas）
        // background: '#f0f'
        // foreground: '#ff0'
      })
    },
    closeCode() {
      this.$refs.qrcode.innerHTML = ''
      this.gameAppQR = false
    }
  },
  mounted() {
    //   <!-- app 遊戲商 -->
    //20秒打一次api member token err 登出
    //用在QR code轉跳手機後 PC版登出
    const timer = setInterval(() => {
      // 某些定时器操作

      this.$store.dispatch('actionMember')
    }, 15000)
    // 通过$once来监听定时器，在beforeDestroy钩子可以被清除。
    this.$once('hook:beforeDestroy', () => {
      clearInterval(timer)
    })
  },

  created() {
    this.$store.dispatch('actionMember')
    // 類型(預設1)1:遊戲列表，2:轉點列表
    let data = {
      type: 1,
      isGetBalance: false
    }
    this.$store.dispatch('actionVendors', data)
    // 彈窗廣告
    this.$store.dispatch('actionBanner', 4)
    // 假資料列表

    if (!this.loging) {
      let listData = []
      this.$store.dispatch('action_myGames', listData)
    }
    // 確認是否可以參加登入活動

    if (
      this.EventMode != 1 &&
      localStorage.getItem('checkLoginEvent' + this.Username) != 1
    ) {
      this.$store.dispatch('actionCheckLoginEvent', 1)
      localStorage.setItem('checkLoginEvent' + this.Username, 1)
    }
  }
}
</script>