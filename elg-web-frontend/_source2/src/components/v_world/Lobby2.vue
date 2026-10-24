<template>
  <div class="Lobby BgContent BgAll">
    <div class="Lobby_barAll">
      <LHeader></LHeader>
    </div>

    <div class="LobbyContent clearfix">
      <div class="float-left blackBg">
        <!--進入EVENTS-->
        <div class="blackPic">
          <img src="../../assets/img/v_world/blackBg.png" />
          <div class="blackImg">
            <a
              href="/Events"
              onclick="ga('send', 'event', 'Lobby', 'LobbyEventBtnClick', 'LobbyEvent');"
            >
              <img src="../../assets/img/v_world/activity.png" />
            </a>
          </div>
        </div>
      </div>
      <!-- 大廳遊戲商 -->
      <div
        v-for="(vendors,i) in getVendors"
        :key="i"
        class="float-left"
        :class="(vendors.status ===2)?'grayBg':'blackBg'"
      >
        <div class="blackPic">
          <img
            :src="(vendors.status ===2)?'../static/img/v_world/grayBg.png':'../static/img/v_world/blackBg.png'"
          />
          <div class="blackImg">
            <a v-if="vendors.status ===1" @click="gameList(vendors);gameEnter(vendors)">
              <img :src="vendors.image" />
            </a>
          </div>
        </div>
        <div class="blackImg">
          <img
            v-if="vendors.status ===2"
            src="../../assets/img/v_world/comingsoon.png"
            class="comingSoon"
          />
          <img v-if="vendors.status ===2" :src="vendors.image" class="disPic" />
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
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import AddOrUpdate from '../v_world/Events'

// eslint-disable-next-line no-unused-vars
import LHeader from '@/components/v_world/Lobby_header'
export default {
  computed: {
    ...mapGetters(['getVendors'])
  },
  components: {
    LHeader
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
    //GA
    // this.$ga.event({
    //   eventCategory: 'Lobby',
    //   eventAction: 'Enter',
    //   eventLabel: 'Lobby'
    //   //   eventValue: 123
    // })
    // var lobbyBanner = sessionStorage.getItem('banner')
    // console.log(lobbyBanner, 'lobby banner')
    // console.log(lobbyBanner, 'bbbbner')
    // if (
    //   lobbyBanner == undefined ||
    //   lobbyBanner == 'undefined' ||
    //   lobbyBanner == '' ||
    //   lobbyBanner == null ||
    //   lobbyBanner == 'null'
    // ) {
    //   $('.Lobby_barAll').css('height', 30 + '%')
    // }
  },
  methods: {
    gameList(vendor) {
      // let name = vendor.name //for app餘額不足彈窗遊戲商名稱,gamelist餘額遊戲商點遊戲playgame(list)帶出來
      sessionStorage.setItem('type_id', '')
      sessionStorage.setItem('vendor_id', vendor.id)
      sessionStorage.setItem('vendor_name', vendor.name)
      sessionStorage.setItem('vendor_balance', vendor.balance)

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
            if (vendor_balance < 30) {
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

    this.$store.dispatch('actionVendors', { isGetBalance: false })
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
