<template>
  <div class="Lobby BgContent BgAll">
    <div class="Lobby_barAll">
      <LHeader></LHeader>
    </div>

    <div class="LobbyContent clearfix">
      <div class="float-left blackBg">
        <!--進入EVENTS-->
        <div class="blackPic">
          <img src="../../assets/img/v_world/blackBg.png" class="blackPicimg" />
          <div class="blackImg">
            <a
              href="/Events"
              onclick="ga('send', 'event', 'Lobby', 'LobbyEventBtnClick', 'LobbyEvent');"
            >
              <img src="../../assets/img/v_world/activity.png" class="blackImgimg" />
            </a>
          </div>
        </div>
      </div>
      <!-- 大廳遊戲商 -->
      <div
        v-for="(vendors,i) in getLobby_Vendors"
        :key="i"
        class="float-left"
        :class="vendors.status ===2||vendors.status ===3?'grayBg':'blackBg'"
      >
        <div class="blackPic">
          <img
            class="blackPicImg"
            :src="vendors.status ===2||vendors.status ===3?'../static/img/v_world/grayBg.png':'../static/img/v_world/blackBg.png'"
          />

          <div class="blackImg" v-if="vendors.status ===1">
            <a @click="gameList(vendors);gameEnter(vendors)">
              <img
                v-if="vendors.is_new===1"
                src="../../assets/img/v_world/newGame.png"
                class="newHotPic newPic blackImgimg"
              />
              <img
                v-if="vendors.is_hot===1"
                src="../../assets/img/v_world/hotGame.png"
                class="newHotPic hotPic"
              />
              <img :src="vendors.image" class="openPic" />
            </a>
          </div>
          <div class="blackImg" v-if="vendors.status ===2">
            <img src="../../assets/img/v_world/comingsoon2.png" class="comingSoon" />
            <img :src="vendors.image" class="disPic" />
          </div>
          <div class="blackImg" v-if="vendors.status ===3">
            <img src="../../assets/img/v_world/maintain25.png" class="maintain" />
            <div class="maintainfont">
              <span>{{vendors.maintenance_end_time.substring(5,16)}} +08</span>
            </div>
            <img :src="vendors.image" class="disPic" />
          </div>
        </div>
      </div>
      <!-- 增加三個框 -->

      <div class="float-left grayBg">
        <div class="blackPic">
          <img src="/static/img/v_world/grayBg.png" class="blackPicimg" />
          <div class="blackImg disPic">
            <a>
              <img src="../../assets/img/v_world/1.png" class="blackImgimg" />
            </a>
          </div>
        </div>
        <div class="blackImg">
          <img src="../../assets/img/v_world/comingsoon2.png" class="comingSoon" />
        </div>
      </div>
      <div class="float-left grayBg">
        <div class="blackPic">
          <img src="/static/img/v_world/grayBg.png" class="blackPicimg" />
          <div class="blackImg disPic">
            <a>
              <img src="../../assets/img/v_world/2.png" class="blackImgimg" />
            </a>
          </div>
        </div>
        <div class="blackImg">
          <img src="../../assets/img/v_world/comingsoon2.png" class="comingSoon" />
        </div>
      </div>
      <div class="float-left grayBg">
        <div class="blackPic">
          <img src="/static/img/v_world/grayBg.png" class="blackPicimg" />
          <div class="blackImg disPic">
            <a>
              <img src="../../assets/img/v_world/3.png" class="blackImgimg" />
            </a>
          </div>
        </div>
        <div class="blackImg">
          <img src="../../assets/img/v_world/comingsoon2.png" class="comingSoon" />
        </div>
      </div>
    </div>
    <!-- 餘額不足 -->
    <div v-if="depositVisible" class="mask depositvisible">
      <div class="modalTitleAll">
        <div class="modalTitle">
          <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
          <div class="modalTitleAll clearfix">
            <div class="contentTitle float-left">{{ $t("insufficientBalance") }}</div>
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
          >{{ template }} {{ $t("amount")}} : {{ vWorld_balance }}</div>
          <div class="nomoneyFont">{{ $t("gamepublisher") }} : {{ vendorName }}</div>
          <div class="info_text_box inputModalBg">
            <input
              class="moneyinput"
              type="number"
              pattern="[0-9]*"
              min="1"
              v-model="depositVal"
              @keyup="inputVal()"
            />
          </div>
          <div class="imgfontGreen im" @click="deposit()">
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
import maskModal from './Modal'
import AddOrUpdate from '../v_world/Events'
import '../../assets/scss/v_world/style.css'
// eslint-disable-next-line no-unused-vars
import LHeader from '@/components/v_world/Lobby_header'
export default {
  computed: {
    ...mapGetters(['getVendors', 'getLobby_Vendors'])
  },
  components: {
    LHeader,
    maskModal
  },
  data() {
    return {
      template: sessionStorage.getItem('Template'),
      vWorld_balance: sessionStorage.getItem('balance'),
      vendorName: '',
      depositVisible: false,
      depositVal: 0
    }
  },
  mounted() {
    $('.LobbyContent').css('display', 'none')
    setTimeout(() => {
      $('.LobbyContent').css('display', 'block')
    }, 1750)
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
    if (
      sessionStorage.getItem('Chatapi_code') == '' ||
      sessionStorage.getItem('Chatapi_code') == undefined
    ) {
      let chatMember = sessionStorage.getItem('chatMember')
      var sign_data = JSON.parse(chatMember)
      var post = {
        info: {},
        url: 'api/member/self'
      }
      //   console.log(post, 'ppp')
      this.$store.dispatch('post', post).then(res => {
        // console.log('hihi')
        if (res.data.code == 1000) {
          sessionStorage.setItem('Chatapi_code', res.data.code)
          //   console.log(res.data.code)
        } else {
          alert(res.data.message)
          //   console.log(res.data.message)
        }
      })
    } else {
      //   console.log('ohohohoh')
    }
  },
  methods: {
    gameList(vendor) {
      //   console.log(vendor, 'vendorlobby')
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
              this.$router.push('/Game')
              this.$store.dispatch('action_vendorInfo')
            }
          })
        } else {
          this.$router.push('/GameList')
        }
      } else if (vendor.need_login == 1) {
        this.$router.push('/GameApp?vendor=' + vendor.name) // ?vendor= for gameApp title //
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
    deposit() {
      let data = {}
      data['isDeposit'] = true
      data['vendor_id'] = sessionStorage.getItem('vendor_id')
      data['point'] = this.depositVal
      //   data['game_id'] = this.game_id
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    }
  },
  created() {
    this.$store.dispatch('actionMember')
    let data = {
      type: 1,
      isGetBalance: false
    }
    this.$store.dispatch('actionVendors', data)
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->

<style  scoped>
.lobbyItem a {
  display: block;
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>
