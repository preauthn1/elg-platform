<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>
    <div class="pcHeader">
      <LobbyPCHeader></LobbyPCHeader>
    </div>
    <div class="single_block singleTop">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2 GamePlay gameTOP">
          <div class="eventBtnD"></div>
          <div class="gameListBar">
            <img
              src="../../assets/img/G733/iii.png"
              class="inform"
              @click="eventinfo()"
              v-if="getEventGameD != undefined &&  getEventGameD != ''"
            />

            <img
              src="../../assets/img/G733/moneyOut.png"
              class="iframeOutBtn"
              @click="visit()"
              v-if="mobilepc=='PC'"
            />
          </div>
          <img id="loading" src="../../assets/img/G733/Loading.gif" class="loadingiframeGame" />
          <!-- 一般遊戲 -->
          <div class="gameBLACK"></div>

          <iframe
            name="target"
            width="100%"
            height="100%"
            frameborder="0"
            scrolling="auto"
            class="iframeG"
            id="iframeG"
          ></iframe>
          <!-- 一般遊戲 需要帳號密碼submit開法 -->
          <form method="post" target="target" id="formOpenGame">
            <input :value="getVendor_Info.user" name="user" type="hidden" />
            <input :value="getVendor_Info.key" name="key" type="hidden" />
            <input :value="getVendor_Info.extension1" name="extension1" type="hidden" />
          </form>
          <!-- vendor單一遊戲 lobby直進 -->
          <form method="post" target="target" id="onlyOneGame"></form>
        </div>
        <!-- 彈窗 -->
        <maskModal></maskModal>
      </div>
    </div>
    <!-- 點數轉入遊戲-->
    <div class="mask" v-if="depositVisible">
      <div class="Popups_Modal">
        <div class="PopupsTitle">
          {{ $t("gameTurnPoint") }}
          <img
            @click="depositVisible = false"
            src="../../assets/img/G733/modalClose.png"
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
                @click="transferOut()"
                class="redBtn btn_all"
              >{{ $t("transferIn") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 全轉點數成功彈窗 -->
    <div class="mask" v-if="isPointsToVendor_success && ToVendor_allPoints > 0">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{ $t("prompt") }}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div style="font-size:14px;">
              {{ $t("theSystemHasTransferred")
              }}{{ ToVendor_allPoints }}{{ $t("pointsTO")
              }}{{ vendor_name }}{{ $t("successfully") }}
            </div>
          </div>
          <div class="PopupsBtn">
            <div class="silverBorder btnBorder_all">
              <button type="submit" @click="close()" class="btn_all blueBtn">{{ $t("confirm") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 活動進入遊戲按鈕詳情 -->
    <div class="mask" v-if="eventGameDetail">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{ $t("participatedActivities") }}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div
              style="margin:auto; width:80%;text-align:left;"
              v-for="(detail, i) in getEventGameD"
              :key="i"
            >ID:{{ detail.id }}&nbsp;TITLE:{{ detail.title }}</div>
          </div>
          <div class="PopupsBtn">
            <div class="silverBorder btnBorder_all">
              <button
                type="submit"
                @click="eventGameDetail = false"
                class="redBtn btn_all"
              >{{ $t("confirm") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 豪龍賠率表彈窗 -->
    <div class="popUp_block" v-if="get_isVendorID4PC&&mobilepc=='PC'">
      <div class="vendorID4">
        <div class="closeBtn" @click="ID4Hide()">
          <img src="../../../static/img/G733/modalClose.png" />
        </div>
        <img :src="lang=='zh-cn'||lang=='zh-tw'?ID4PicCH:ID4PicEN" />
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import LobbyPCHeader from '@/components/G733/Lobby_PCheader'
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import Bus from './Bus.js'
export default {
  data() {
    return {
      vendor_id: window.sessionStorage.getItem('vendor_id'),
      screenHeight: window.innerHeight, //iframe高度

      template_name: sessionStorage.getItem('TemplateTitle'),
      vWorld_balance: sessionStorage.getItem('balance'),
      vendor_name: sessionStorage.getItem('vendor_name'),

      depositVisible: false,
      depositPoint: 0,
      balance: sessionStorage.getItem('balance'),
      template_name: sessionStorage.getItem('TemplateTitle'),
      vendor_name: sessionStorage.getItem('vendor_name'),
      transaction_all: sessionStorage.getItem('transaction_all'),
      points: 0,
      repeat: false,
      visibleNote: false,

      isPointsToVendor_success: false,
      ToVendor_allPoints: null,
      eventGameDetail: false,
      name: 0,
      //  pt使用者資訊
      ptUserdata: '',
      // 語系
      lang: localStorage.getItem('LOCALE'),
      //   豪龍賠率表圖片
      ID4PicEN: require('../../../static/img/G733/s1.jpg'),
      ID4PicCH: require('../../../static/img/G733/s2.jpg'),
      mobilepc: sessionStorage.getItem('PC_MOBILE')
    }
  },
  components: {
    LoginHeader,
    LobbyPCHeader,
    maskModal
  },
  computed: {
    ...mapGetters([
      'getGameUrl',
      'getVendor_Info',
      'getVendors',
      'getVendor',
      'getNewvpoints',
      'getEventGameD',
      'getGameData',
      'getGameList',
      'getPtData',
      'get_isVendorID4',
      'get_isVendorID4PC',
      'get_singleBalance'
    ])
  },
  mounted() {
    //   PT用
    let recaptchaScript = document.createElement('script')
    recaptchaScript.setAttribute(
      'src',
      'https://example.invalid/jswrapper/integration.js.php?casino=winforfun88'
    )
    document.head.appendChild(recaptchaScript)

    //   header活動說明按鈕click接收
    var vm = this
    // 用$on事件来接收参数
    Bus.$on('val', data => {
      this.eventGameDetail = true
    })
    // 單一錢包
    if (this.transaction_all == 1) {
      this.allInOne()
    }
    // mobile
    if (this.mobilepc == 'MOBILE') {
      //iframe高度
      const that = this
      window.onresize = () => {
        return (() => {
          window.screenHeight = window.innerHeight
          that.screenHeight = window.screenHeight
        })()
      }
      //第一次
      var css = {
        height: that.screenHeight - 46 + 'px'
      }
      $('.GamePlay').css(css)
    }
    // pc
    else if (this.mobilepc == 'PC') {
      const that = this
      window.screenHeight = window.innerHeight
      that.screenHeight = window.screenHeight

      $('.GamePlay').css('height', that.screenHeight - 143 + 'px')
      $('.gameBLACK').css('height', that.screenHeight - 143 + 'px')
    }

    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        //   橫
        $('.directionMask').css('display', 'none')
      } else {
        //   直
        $('.directionMask').css('display', 'none')
      }
    }
    // IFRAME跑完LOADING圖片消失
    $(document).ready(function() {
      $('.iframeG').on('load', function() {
        $('#loading').hide()
        var enterGameName = sessionStorage.getItem('enterGameName')

        ga('send', 'event', {
          eventCategory: 'Game',
          eventAction: 'Game_Enter',
          eventLabel: enterGameName,
          eventValue: enterGameId
        })
      })
    })
  },
  //監聽iframe高度
  watch: {
    screenHeight(val) {
      //   為了避免平凡的觸發RESIZE函數導致頁面卡頓,使用定時器
      if (!this.timer) {
        //   一旦監聽的SCREENHEIGHT的值改變,就將其重新給DATA裡的SCREENHEIGHT
        this.screenHeight = val
        this.timer = true
        let that = this
        setTimeout(function() {
          // screenWidth變化的值
          that.timer = false
        }, 400)
        $('.directionMask').css('display', 'none')
        var css = {
          height: that.screenHeight - 55 + 'px'
        }
        $('.GamePlay').css(css)
      }
    }
  },

  methods: {
    //   關閉PC版豪龍賠率彈窗
    ID4Hide() {
      this.$store.commit('HIDE_VENDORID4_PC')
    },
    getData: function() {
      this.name++
    },
    game() {
      // 手機板
      if (this.mobilepc == 'MOBILE') {
        let gameID = this.$route.query.id
        if (gameID == 4 || gameID == 0 || gameID == 18) {
          //! 目前改彈窗
          window.location = '/ButtonClick/' + gameID
        } else {
          setTimeout(() => {
            let query = window.location.search.split('=')
            let id = query[1]
            let vendorID = this.getGameData.vendor_id
            if (id) {
              // pt
              if (vendorID == '17') {
                let data = {
                  vendorID: vendorID,
                  gameID: id
                }
                this.$store.dispatch('actionGameUrl', data)
                setTimeout(() => {
                  let ptUserdata = {
                    username: this.getPtData.username,
                    password: [REDACTED],
                    gametype: this.getPtData.game_type
                  }

                  iapiSetCallout('Login', calloutLogin)
                  //   接至INDEX.HTML
                  login(ptUserdata)
                }, 1500)
              } else {
                let data = {
                  vendorID: vendorID,
                  gameID: id
                }
                this.$store.dispatch('actionGameUrl', data)
              }
            } else {
              this.$store.dispatch('action_vendorInfo')
            }
          }, 2000)

          setTimeout(() => {
            // 更改title名稱
            var gameName = this.getGameData.name
            var vendorName = this.getGameData.vendor_name
            var vendorID = this.getGameData.vendor_id

            $('.loginbarName').html(vendorName + ' / ' + gameName)
            $('.directionMask').css('display', 'none')
          }, 2500)
        }
      }
      //   PC版
      else if (this.mobilepc == 'PC') {
        setTimeout(() => {
          let query = window.location.search.split('&')
          let qID = query[0]
          let idsplit = qID.split('=')
          let id = idsplit[1]
          if (id) {
            //   PT
            if (this.getGameData.vendor_id == '17') {
              let data = {
                vendorID: this.getGameData.vendor_id,
                gameID: idsplit[1]
              }
              this.$store.dispatch('actionGameUrl', data)

              setTimeout(() => {
                let ptUserdata = {
                  username: this.getPtData.username,
                  password: [REDACTED],
                  gametype: this.getPtData.game_type
                }
                iapiSetCallout('Login', calloutLogin)
                //   接至INDEX.HTML
                login(ptUserdata)
              }, 1500)
            } else {
              let data = {
                vendorID: this.getGameData.vendor_id,
                gameID: idsplit[1]
              }
              this.$store.dispatch('actionGameUrl', data)
            }
          } else {
            this.$store.dispatch('action_vendorInfo')
          }
        }, 2000)
      }
    },

    close() {
      this.isPointsToVendor_success = false
    },
    // 轉點
    visit() {
      this.depositVisible = true
    },
    // 點數轉入彈窗驗證
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
    // 遊戲有參與活動的按鈕
    eventinfo() {
      this.eventGameDetail = true
    },

    //! 開關 全轉入遊戲 1.allInOne  2.transferAll_In
    //! 全轉遊戲改打單一錢包API
    allInOne() {
      setTimeout(() => {
        let vendorID = this.getGameData.vendor_id
        this.$store.dispatch('singleWalletBalance', vendorID).then(success => {
          this.isPointsToVendor_success = success //轉點完成開啟成功彈窗

          this.ToVendor_allPoints = parseInt(
            //     將欲轉點之點數顯示至提示窗上
            this.get_singleBalance.toString().replace(',', ''),
            10
          )
        })
      }, 1000)
    },

    transferOut(isAllIn) {
      this.depositVisible = false
      let data = {}
      data['vendor_id'] = this.vendor_id
      data['vendor_name'] = this.vendor_name
      if (isAllIn == 'AllIn') {
        if (this.getNewvpoints) {
          data['point'] = parseInt(
            this.getNewvpoints.toString().replace(',', ''),
            10
          )
          this.ToVendor_allPoints = parseInt(
            this.getNewvpoints.toString().replace(',', ''),
            10
          )
        } else {
          data['point'] = parseInt(this.balance.toString().replace(',', ''), 10)
          this.ToVendor_allPoints = parseInt(
            this.balance.toString().replace(',', ''),
            10
          )
        }
      } else {
        data['point'] = this.depositPoint
      }
      if (data.point != 0) {
        if (this.vendor_id == '3') {
          if (this.repeat == false) {
            this.repeat = true // 防15秒
            this.$store.dispatch('balanceToVendor', data).then(success => {
              this.isPointsToVendor_success = success
            })
            setTimeout(() => {
              this.repeat = false
            }, 15000)
          } else if (this.repeat == true) {
            this.visibleNote = true
          }
        } else {
          this.$store.dispatch('balanceToVendor', data).then(success => {
            this.isPointsToVendor_success = success
          })
        }

        ga('send', 'event', {
          eventCategory: 'Points',
          eventAction: 'TransferOut_Click',
          eventLabel: this.vendor_name,
          eventValue: this.points
        })
      }
    }
  },

  created() {
    //   類型(預設1)1:遊戲列表，2:轉點列表
    let data = {
      type: 2,
      isGetBalance: true
    }
    this.$store.dispatch('actionVendors', data)
    var gameID = this.$route.query.id
    this.$store.dispatch('actionGame', gameID)

    this.game()
  }
}
</script>

