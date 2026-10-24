<template>
  <div id="G733">
    <div class="LoginHeader gary_header page clearfix">
      <div class="pageTitleAll clearfix">
        <div class="loginbarBack float-left">
          <img src="../../assets/img/G733/back.png" @click="back()" v-if="$route.meta.back" />
        </div>
        <div class="gamePointTransfer float-left"></div>
        <div class="loginbarTitle float-left">
          <img
            src="../../assets/img/C711_Mobile/iii.png"
            style="width:15px; padding-bottom:4px;"
            @click="elementByValue"
            v-if="getEventGameD != undefined &&getEventGameD != ''"
          />
          <span class="loginbarName">{{ $t($route.meta.title) }}</span>
        </div>
        <div class="gamePointTransfer float-left">
          <img
            src="../../assets/img/G733/moneyOut.png"
            @click="pointTransfer()"
            v-if="$route.meta.gamePointBtn"
            style="width:22px;"
          />
        </div>
        <div class="loginbarright float-left searchBtn">
          <a href="/DateSelection">
            <img
              src="../../assets/img/G733/barsearch.png"
              @click="serchTime()"
              v-if="$route.meta.searchTime"
              class="searchPic"
            />
          </a>
          <img
            src="../../assets/img/G733/home.png"
            style="width:26px;"
            @click="goLobby()"
            v-if="$route.meta.home"
          />
        </div>
      </div>
      <!-- game遊戲中 儲值-->

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
                  @click="normal_deposit()"
                  class="redBtn btn_all"
                >{{ $t("transferIn") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
import Bus from './Bus.js'
import '../../assets/scss/G733/style.css'
import '../../assets/scss/G733/css.css'
import maskModal from './Modal'
export default {
  components: {
    maskModal
  },

  data() {
    return {
      depositVisible: false,
      template_name: sessionStorage.getItem('TemplateTitle'),
      vWorld_balance: sessionStorage.getItem('balance'),
      vendor_name: sessionStorage.getItem('vendor_name'),
      depositPoint: 0,

      eventGame_detail: '',
      elementValue: 4,
      specialPic: '',
      //   鎖分
      EventMode: sessionStorage.getItem('eventMode'),
      eventOpenGame: this.$route.params.eventOpenGame
    }
  },
  computed: {
    ...mapGetters(['isFinish', 'getEventGameD'])
  },
  methods: {
    back() {
      let vendor_id = sessionStorage.getItem('vendor_id')

      //   判斷有無進列表

      let need_login = sessionStorage.getItem('need_login')
      let only_one_game = sessionStorage.getItem('only_one_game')
      if (location.pathname == '/ButtonClick') {
        this.$router.push('/')
      } else if (only_one_game == 1 && location.pathname == '/Game') {
        this.$router.push('/')
      } else if (need_login == 0 && location.pathname == '/Game') {
        this.$router.push('/GameList/' + vendor_id)
      } else if (location.pathname == '/GameList/' + vendor_id) {
        this.$router.push('/')
      } else if (location.pathname == '/GameApp') {
        this.$router.push('/')
      } else {
        this.$router.go(-1)
      }
    },
    // 呼叫game 彈窗
    elementByValue: function() {
      Bus.$emit('val', this.elementValue)
    },
    goLobby() {
      window.location = '/'
    },
    serchTime() {
      this.$router.push('/DateSelection')
    },
    finish(type) {
      let data = {
        type: type,
        verifyCode: window.sessionStorage.getItem('verify_Code')
      }
      this.$store.dispatch('actionCheckCode', data)
    },

    /// game遊戲中 轉點
    pointTransfer() {
      this.depositVisible = true
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
    }
  },
  mounted() {
    //手機方向
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
    //   判斷遊戲頁面不要出現節日圖片
    if (location.pathname == '/Game') {
      this.specialPic = 1
    } else {
      this.specialPic = 2
    }
  }
}
</script>
