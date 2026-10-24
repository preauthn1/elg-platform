<template>
  <div id="G733">
    <div class="PointsHeader gary_header">
      <div class="backBtn">
        <img src="../../assets/img/G733/back.png" @click="back()" v-if="$route.meta.back" />
        <img
          src="../../assets/img/C711_Mobile/back.png"
          @click="gameListBack()"
          v-if="$route.meta.gameListBack"
        />
      </div>
      <!-- 點數 LOBBY RECORD REDENVELOPERECORD SETTING-->
      <div class="points" v-if="$route.meta.settingPoints">
        <img src="../../assets/img/G733/GrayPoint.png" />
        <div class="reBtn" v-if="transaction_all==0">
          <img src="../../assets/img/G733/reBtnBlue.png" @click="reloadBalance()" />
        </div>
        <!-- 點數自動轉入 取全部餘額 -->
        <div class="reBtn" v-if="transaction_all==1">
          <img src="../../assets/img/G733/reBtnBlue.png" @click="reloadAllModal()" />
        </div>
        <!-- 單一 一般共用 -->
        <div class="pointsFont">{{ $t("points") }}：{{ getAcctInfo.balance }}</div>
        <div class="addBtn" v-if="has_online_payment == 1 && EventMode == 0">
          <a
            href="/StoredValue"
            onclick="ga('send', 'event', 'StoredBtn', 'StoredBtn_Click', 'Stored');"
          >
            <img src="../../assets/img/G733/add.png" />
          </a>
        </div>
        <!-- 平台活動(鎖分) 解鎖 -->
        <div class="pointLockBtn" v-if="EventMode ==1">
          <button
            type="button"
            class="form-control lockHeight fontsizeLock blue_BtnBg"
            @click="UnLockEventMode()"
          >
            <span>Unlock</span>
          </button>
        </div>
      </div>
      <div class="marquee_Pos">
        <Marquee></Marquee>
      </div>
      <!-- 點數 POINTS-->
      <!-- 黑底無重整 -->
      <div class="points" v-if="$route.meta.getPoints">
        <img src="../../assets/img/G733/GrayPoint.png" />
        <div class="reBtn">
          <img src="../../assets/img/G733/reBtnBlue.png" @click="reloadBalance()" />
        </div>
        <div class="pointsFont">{{ $t("points") }}：{{ getPoint }}</div>
        <div class="addBtn" v-if="has_online_payment == 1&& EventMode == 0">
          <a
            href="/StoredValue"
            onclick="ga('send', 'event', 'StoredBtn', 'StoredBtn_Click', 'Stored');"
          >
            <img src="../../assets/img/G733/add.png" />
          </a>
        </div>
        <div class="pointLockBtn" v-if="EventMode ==1">
          <button
            type="button"
            class="form-control lockHeight fontsizeLock blue_BtnBg"
            @click="UnLockEventMode()"
          >
            <span>Unlock</span>
          </button>
        </div>
      </div>
      <!-- 點數 GAMELIST-->
      <div class="points" v-if="$route.meta.getBalance">
        <img src="../../assets/img/G733/GrayPoint.png" />

        <div class="pointsFont">{{ $t("points") }}：{{ getBalance }}</div>
        <div class="pointLockBtn" v-if="EventMode ==1">
          <button
            type="button"
            class="form-control lockHeight fontsizeLock blue_BtnBg"
            @click="UnLockEventMode()"
          >
            <span>Unlock</span>
          </button>
        </div>
      </div>
      <div class="user">
        <a href="/RecordList" onclick="ga('send', 'event', 'Wallet', 'RecordBtn_Click', 'Record');">
          <img src="../../assets/img/G733/record.png" v-if="$route.meta.record" />
        </a>
        <img
          src="../../assets/img/G733/hamburger.png"
          v-on:click="show = !show"
          v-if="$route.meta.hamburger"
        />
        <img
          src="../../assets/img/C711_Mobile/home.png"
          class="homeBtn"
          @click="goLobby()"
          v-if="$route.meta.home"
        />
        <img
          src="../../assets/img/C711_Mobile/moneyOut1.png"
          @click="pointTransfer()"
          v-if="$route.meta.gamePointBtn&&eventOpenGame!=1"
          style="width:22px;"
          id="moneyOutBtn"
        />
      </div>
    </div>
    <!-- 漢堡選單 -->
    <div class="transition_hamburger">
      <transition name="fade">
        <div v-if="show">
          <div class="fadeMenu">
            <div class="panel-group" id="accordion">
              <div class="panel">
                <div>{{$t('gamepublisher')}}</div>
                <div class="clearfix">
                  <div
                    class="float-left"
                    v-for="(vendors, i) in getLobby_Vendors"
                    :key="i"
                    v-if="vendors.status == 1"
                  >
                    <a @click="gameList(vendors);gameEnter(vendors);">
                      <div class="silverBorder btnBorder_all" style="width:inherit; margin:5px;">
                        <div
                          class="grayBtn btn_all removeVendorbg"
                          :id="'vendor_type'+vendors.id"
                        >{{ vendors.name }}</div>
                      </div>
                    </a>
                  </div>
                </div>
              </div>
              <div class="panel" v-if="pathname !='/Lobby'">
                <div>{{$t('gameCategory')}}</div>
                <div class="clearfix">
                  <div
                    class="silverBorder btnBorder_all float-left"
                    style="width:inherit; margin:5px;"
                    v-for="(gameType,i) in getGameTypes.data"
                    :key="i"
                    v-if="gameType.number!=0"
                  >
                    <div
                      class="grayBtn btn_all removebg"
                      @click="getGamelist(gameType.id)"
                      :id="'type'+gameType.id"
                    >{{ gameType.name }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </transition>
    </div>
    <!-- game遊戲中 儲值 -->
    <div class="mask" v-if="depositVisible">
      <div class="Popups_Modal">
        <div class="PopupsTitle">
          {{ $t("gameTurnPoint") }}
          <img
            @click="depositVisible = false"
            src="../../assets/img/C711_Mobile/modalClose.png"
            class="modalClose"
          />
        </div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div class="modalBalance">
              {{ template_name }}{{ $t("amount") }} :
              {{ vWorld_balance }}
            </div>
            <div class="modalBalance">{{ $t("gamepublisher") }} : {{ vendor_name }}</div>
            <div class="inputvalidate" style="width:80%; margin:8px auto;">
              <div class="goldBorder btnBorder_all" style="width:90%; margin:auto;">
                <div class="ListborderInput">
                  <input
                    class="blackBtn settingBtn fontsizelogin"
                    type="number"
                    pattern="[0-9]*"
                    min="1"
                    v-model="depositPoint"
                    @keyup="inputVal()"
                    style="text-align:right;"
                  />
                </div>
              </div>
            </div>
          </div>
          <div class="PopupsBtn">
            <div class="silverBorder btnBorder_all">
              <button
                type="submit"
                @click="normal_deposit()"
                class="redBtn btn_all"
              >{{ $t("transferIn") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 解鎖彈窗  -->

    <div class="popUp_block" v-if="get_isUnlockEvent">
      <div class="wrap" style="top:20%;">
        <div class="single_block">
          <div class="case_wrapIframe">
            <div>
              <div class="title">
                {{ $t("eventModeSignOff") }}
                <img
                  @click="modeModal()"
                  class="close_icon"
                  src="../../../static/img/C711_PC/Group5301.png"
                />
              </div>
              <div class="form">
                <div class="orangeTitle" style="margin:20px auto;">
                  <div
                    class="forceFont"
                  >{{ $t("youCurrentlyHave") }}{{get_unlockEvent.balance}}{{ $t("point") }}，{{ $t("areYousureThat") }}{{get_unlockEvent.point*get_unlockEvent.times}}{{ $t("point") }}{{ $t("ifYouSignOffNow") }}</div>
                </div>
              </div>
              <div class="footer clearfix" style="width:90%;margin:auto;">
                <div class="silverBorder btnBorder_all float-left" @click="forcibly(1)">
                  <button type="button" class="btn_all yellowBtn">{{ $t("determine") }}</button>
                </div>

                <div class="silverBorder btnBorder_all float-right" @click="modeModal()">
                  <button type="button" class="blueBtn btn_all">{{$t('cancel')}}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 自動帶點模式 點數重整提示 -->
    <div class="mask" v-if="oneWallet">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{$t('prompt')}}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div style="width:80%;margin:auto;line-height:25px;">{{$t('checkingTheBalance')}}</div>
          </div>
          <div class="PopupsBtn clearfix">
            <div class="silverBorder btnBorder_all float-left">
              <button
                type="submit"
                @click="reloadAllBalance()"
                class="btn_all yellowBtn"
              >{{ $t("confirm") }}</button>
            </div>

            <div class="silverBorder btnBorder_all float-right" @click="modeModal()">
              <button
                type="submit"
                @click="oneWallet  = false"
                class="blueBtn btn_all"
              >{{ $t("cancel") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import '../../assets/scss/G733/style.css'
import '../../assets/scss/G733/css.css'
import { mapGetters, mapActions } from 'vuex'
import Marquee from '@/components/G733/Marquee'
import maskModal from './Modal'
export default {
  components: {
    maskModal,
    Marquee
  },
  computed: {
    ...mapGetters([
      'getAcctInfo',
      'getVendors',
      'getNewvpoints',
      'getNew_VendorBalance',
      'getVendor_balance',
      'getGameTypes',
      'getLobby_Vendors',
      'getMenu',
      //   鎖分活動
      'get_checkEvent',
      'get_isUnlockEvent',
      'get_unlockEvent',
      'getRecordPoints',
      //   單一錢包
      'get_singleBalance'
    ]),
    getPoint() {
      let vpoints = this.getNewvpoints
        ? this.getNewvpoints
        : sessionStorage.getItem('balance')
      return vpoints
    },
    getBalance() {
      let vendorPoints = this.getNew_VendorBalance
        ? this.getNew_VendorBalance
        : this.getVendor_balance
      return vendorPoints
    }
  },
  data() {
    return {
      vPoints: window.sessionStorage.getItem('balance'),
      balance: window.sessionStorage.getItem('balance'),
      depositVisible: false,
      template_name: sessionStorage.getItem('TemplateTitle'),
      vWorld_balance: sessionStorage.getItem('balance'),
      vendor_name: sessionStorage.getItem('vendor_name'),
      depositPoint: 0,
      has_online_payment: sessionStorage.getItem('has_online_payment'),
      transaction_all: sessionStorage.getItem('transaction_all'),
      show: false,
      pathname: window.location.pathname,

      vendors_type: [],
      vendorStr: '', // vendorId 字串上路由
      vendorName: [], //
      mobilepc: sessionStorage.getItem('PC_MOBILE'),
      EventMode: sessionStorage.getItem('eventMode'),
      transaction_all: sessionStorage.getItem('transaction_all'),
      //   自動帶點,最後一筆轉點紀錄 點數
      oneWallet: false,
      eventOpenGame: this.$route.params.eventOpenGame
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
    // 畫面進入被選的出現黃色

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
  },
  methods: {
    back() {
      let vendor_id = sessionStorage.getItem('vendor_id')
      let need_login = sessionStorage.getItem('need_login')
      let only_one_game = sessionStorage.getItem('only_one_game')
      if (location.pathname == '/ButtonClick') {
        this.$router.push('/')
      } else if (only_one_game == 1 && location.pathname == '/Game') {
        this.$router.push('/')
      } else if (need_login == 0 && location.pathname == '/Game') {
        this.$router.push('/GameList')
      } else if (
        location.pathname == '/GameList' ||
        location.pathname == '/GameList/' + vendor_id
      ) {
        this.$router.push('/')
      } else if (location.pathname == '/GameApp') {
        this.$router.push('/')
      } else {
        this.$router.go(-1)
      }
    },

    gameList(vendor) {
      // let name = vendor.name //for app餘額不足彈窗遊戲商名稱,gamelist餘額遊戲商點遊戲playgame(list)帶出來
      $('.removeVendorbg').removeClass('yellowBtn')
      $('.removeVendorbg').addClass('grayBtn')
      $('#vendor_type' + vendor.id).removeClass('grayBtn')
      $('#vendor_type' + vendor.id).addClass('yellowBtn')
      sessionStorage.setItem('type_id', '')
      sessionStorage.setItem('vendor_id', vendor.id)
      sessionStorage.setItem('vendor_name', vendor.name)
      sessionStorage.setItem('vendor_balance', vendor.balance)
      sessionStorage.setItem('need_login', vendor.need_login)
      sessionStorage.setItem('only_one_game', vendor.only_one_game)

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
              // 目前只有豪龍 sbo

              this.$router.push('/Game?id=' + vendor.id)
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
    //GA判斷用
    gameEnter(vendor) {
      if (vendor.only_one_game == 1) {
        var enterGameName = vendor.name
        var enterGameId = vendor.id
        sessionStorage.setItem('enterGameName', enterGameName)
        sessionStorage.setItem('enterGameId', enterGameId)
      }
    },
    gameListBack() {
      this.$router.push('/')
    },
    goLobby() {
      window.location = '/'
    },
    /// game遊戲中 轉點
    pointTransfer() {
      this.depositVisible = true
      this.vendor_name = sessionStorage.getItem('vendor_name')
    },
    inputVal() {
      this.depositPoint = parseInt(this.depositPoint, 10)
      let vPoints = parseInt(
        this.vWorld_balance.toString().replace(',', ''),
        10
      )
      if (this.depositPoint >= vPoints) {
        this.depositPoint = vPoints
      } else if (!this.depositPoint) {
        this.depositPoint = 0
      }
    },
    // 一般轉點
    normal_deposit() {
      let data = {}
      data['vendor_id'] = sessionStorage.getItem('vendor_id')
      data['point'] = this.depositPoint
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    },
    // 重新取平台點數
    reloadBalance() {
      this.$store.dispatch('actionMember')
    },
    // 活動解鎖
    UnLockEventMode() {
      this.$store.dispatch('actionUnLoginEvent')
    },
    // 關閉強制解鎖彈窗
    modeModal() {
      this.$store.commit('HIDE_UNLOCK_EVENT')
    },
    // 強制解鎖
    forcibly(data) {
      this.$store.dispatch('actionUnLoginEvent', data)
      this.$store.commit('HIDE_UNLOCK_EVENT')
    },
    // 單一錢包彈窗
    reloadAllModal() {
      this.oneWallet = true
    },
    // 自動轉點 RE LODAD取全部餘額按鈕
    reloadAllBalance() {
      let data = ''
      this.$store.dispatch('singleWalletBalance', data)
      this.oneWallet = false
      //   $('.zero').css('display', 'none')
    },
    getGamelist(data) {
      $('.removebg').removeClass('yellowBtn')
      $('.removebg').addClass('grayBtn')
      $('#type' + data).removeClass('grayBtn')
      $('#type' + data).addClass('yellowBtn')

      this.searchKey = ''
      if (data == undefined || data == 0) {
        sessionStorage.setItem('type_id', '')
      }
      sessionStorage.setItem('personal', '')

      let listData = {
        vendor_id: this.$route.params.vendorID,
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
      this.show = false
    }
  },
  created() {
    this.$store.dispatch('actionMember')
    this.$store.dispatch('actionMenu')
    this.$store.dispatch('action_gameTypes', this.$route.params.vendorID)
    // 類型(預設1)1:遊戲列表，2:轉點列表
    let data = {
      type: 1,
      isGetBalance: true
    }
    this.$store.dispatch('actionVendors', data)
  }
}
</script>
<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: all 0.4s ease;
}

.fade-enter {
  transform: translateY(-50px);
  opacity: 0;
}

.fade-leave-to {
  transform: translateY(50px);
  opacity: 0;
}
</style>