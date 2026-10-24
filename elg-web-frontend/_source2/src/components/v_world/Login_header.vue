<template>
  <div class="BgContent BgAll" style="height:44px; position: relative;">
    <img src="../../assets/img/v_world/Xmas_TOP.png" class="speciallogT" v-if="specialPic==2" />
    <div class="homeTitle clearfix">
      <div class="loginbarBack float-left">
        <img src="../../assets/img/v_world/back1.png" @click="back()" v-if="$route.meta.back" />
      </div>
      <div class="gamePointTransfer float-left"></div>
      <div class="loginbarTitle float-left">
        <span>{{ $t($route.meta.title) }}</span>
      </div>
      <div class="gamePointTransfer float-left">
        <img
          src="../../assets/img/v_world/gamepointTop.png"
          @click="pointTransfer()"
          v-if="$route.meta.gamePointBtn"
        />
      </div>
      <div class="loginbarright float-left">
        <div
          id="pass"
          @click="finish('newPass')"
          :disabled="!isFinish"
          v-if="$route.meta.setNewpass"
        >
          <span>{{ $t("finish") }}</span>
        </div>
        <div @click="finish('verify')" :disabled="!isFinish" v-if="$route.meta.verifyFinish">
          <span>{{ $t("finish") }}</span>
        </div>
        <a href="/DateSelection">
          <img
            src="../../assets/img/v_world/barsearch.png"
            @click="serchTime()"
            v-if="$route.meta.searchTime"
          />
        </a>
        <img src="../../assets/img/v_world/home.png" @click="goLobby()" v-if="$route.meta.home" />
      </div>
    </div>
    <!-- game遊戲中 儲值 -->
    <div v-if="depositVisible" class="mask depositvisible">
      <div class="modalTitleAll">
        <div class="modalTitle">
          <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
          <div class="modalTitleAll clearfix">
            <div class="contentTitle float-left">{{$t('gameTurnPoint')}}</div>
            <img
              @click="depositVisible = false"
              src="../../assets/img/v_world/closeBtn.png"
              class="closeBtn float-right"
            />
          </div>
        </div>
        <div class="modalContentAll">
          <div
            class="nomoneyFont"
            style="padding-top:20px;"
          >{{ template_name }}{{ $t("amount") }} : {{ vWorld_balance }}</div>
          <div class="nomoneyFont">{{ $t("gamepublisher") }} : {{ vendor_name }}</div>
          <div class="info_text_box inputModalBg">
            <input
              class="moneyinput"
              type="number"
              pattern="[0-9]*"
              min="1"
              v-model="depositPoint"
              @keyup="inputVal()"
            />
          </div>
          <div class="imgfontGreen im" @click="normal_deposit()">
            <span>{{ $t("transferIn") }}</span>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import { truncate } from 'fs'
import { EDEADLK } from 'constants'
import '../../assets/scss/v_world/style.css'
import maskModal from './Modal'
export default {
  components: {
    maskModal
  },
  data() {
    return {
      depositVisible: false,
      template_name: sessionStorage.getItem('Template'),
      vWorld_balance: sessionStorage.getItem('balance'),
      vendor_name: sessionStorage.getItem('vendor_name'),
      depositPoint: 0,
      specialPic: ''
    }
  },
  computed: {
    ...mapGetters(['isFinish'])
  },
  methods: {
    back() {
      //   console.log(111)
      //
      let vendor_id = sessionStorage.getItem('vendor_id')
      //   判斷有無進列表
      let need_login = sessionStorage.getItem('need_login')
      let only_one_game = sessionStorage.getItem('only_one_game')

      if (location.pathname == '/ButtonClick') {
        this.$router.push('/')
      } else if (only_one_game == 1 && location.pathname == '/Game') {
        this.$router.push('/')
        // console.log(only_one_game, 'only_one_game', location.pathname)
      } else if (need_login == 0 && location.pathname == '/Game') {
        // console.log('這裡')
        this.$router.push('/GameList')
        // console.log(need_login, 'need_login', location.pathname)
      } else if (location.pathname == '/GameList') {
        this.$router.push('/')
        // console.log(location.pathname)
      } else {
        this.$router.go(-1)
        // console.log('no')
      }

      //   if (vendor_id == '4' || location.pathname == '/Game') {
      //     this.$router.push('/')
      //   } else if (location.pathname == '/GameList') {
      //     this.$router.push('/')
      //   } else if (
      //     (vendor_id == '1' || vendor_id == '13') &&
      //     location.pathname == '/Game'
      //   ) {
      //     this.$router.push('/GameList')
      //     // console.log('game:1 & 13')
      //   } else {
      //     // window.history.go(-1)
      //     this.$router.go(-1)
      //   }
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
    //   判斷遊戲頁面不要出現節日圖片
    if (location.pathname == '/Game') {
      this.specialPic = 1
      //   console.log(this.specialPic, '聖誕節的燈')
    } else {
      this.specialPic = 2
      //   console.log(this.specialPic, '聖誕節的燈')
    }
    // this.depositPoint = Math.round
    // parseInt(this.vWorld_balance.toString().replace(',', ''), 10) / 2
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  >
</style>
