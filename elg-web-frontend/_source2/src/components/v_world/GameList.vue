<template>
  <div class="footerBtnBottom BgContent BgAll">
    <!-- BgContent BgContentGameList-->
    <LoginHeader></LoginHeader>
    <div class="GameListPadding">
      <div class="GameListMenu BgContent">
        <div v-if="hide" class="wrapper clearfix">
          <div class="float-left pointbg">
            <div class="info_text_box GameListInput clearfix">
              {{ $t("points") }}:
              {{ getBalance }}
              <div class="float-right gameListBtnPic">
                <img src="../../assets/img/v_world/gamepointout.png" @click="pointTransfer(2)" />
              </div>
              <div class="float-right gameListBtnPic">
                <img src="../../assets/img/v_world/gamepoint.png" @click="pointTransfer(1)" />
              </div>
            </div>
          </div>
          <div class="float-right GameListBtn">
            <Slide width="200" Position="right">
              <div @click="getGamelist()" class="gamefont">
                <span>{{ $t("allGames") }} ({{getGameTypes.total}})</span>
              </div>
              <div
                @click="getGamelist(gameType.id)"
                v-for="(gameType,i) in getGameTypes.data"
                :key="i"
                class="gamefont"
              >
                <span>{{ gameType.name }}( {{ gameType.number }} )</span>
              </div>
              <div @click="getFavoriteList(1)" class="gamefont">
                <span>{{ $t("myFavourite") }}( {{getFavoriteLength}} )</span>
              </div>
            </Slide>
          </div>
        </div>
        <div class="search clearfix">
          <div class="float-left searchinput">
            <input
              v-model="searchKey"
              class="form-control inputbg"
              id="Search"
              :placeholder="$t('search')"
            />
          </div>
          <div @click="searchGame()" class="float-right searchBtn">
            <i class="fa fa-search searchicon"></i>
          </div>
        </div>
      </div>
      <div class="GameListItem clearfix">
        <div v-for="(list,i) in getGameList" :key="i" class="float-left gameHeart">
          <!--進入Game-->
          <a @click="playGame(list)">
            <div class="GameListImg">
              <img :src="list.image" />
            </div>
            <div class="GameListFont">{{ list.name }}</div>
          </a>
          <img
            @click="favorite({list,i})"
            v-if="list.is_favourite == 0"
            class="Heart"
            src="../../assets/img/v_world/H1.png"
          />
          <img
            @click="favorite({list,i})"
            v-if="list.is_favourite == 1"
            class="Heart"
            src="../../assets/img/v_world/H2.png"
          />
        </div>
      </div>
      <!-- playGame comfirm -->
      <div v-if="comfirmVisible" class="mask">
        <div class="modalBlueAll modalContent">
          <p>{{ $t("areYouSureToEnterTheGame") }}</p>
          <div class="comfirmBtnAll">
            <div class="float-left greenBtn modalBtn" @click="comfirm">{{ $t("confirm") }}</div>
            <div class="float-right redBtn modalBtn" @click="cancel">{{ $t("cancel") }}</div>
          </div>
        </div>
      </div>
      <!-- 餘額不足 轉點 -->

      <div v-if="depositVisible" class="mask depositvisible">
        <div class="modalTitleAll">
          <div class="modalTitle">
            <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
            <div class="modalTitleAll clearfix">
              <div
                v-if="!isNormal_deposit"
                class="contentTitle float-left"
              >{{ $t("insufficientBalance") }}</div>
              <div v-if="isNormal_deposit" class="contentTitle float-left">{{$t('gameTurnPoint')}}</div>
              <img
                @click="depositVisible = false"
                src="../../assets/img/v_world/closeBtn.png"
                class="closeBtn float-right"
              />
            </div>
          </div>
          <div class="modalContentAll" v-if="transferInOut == 2">
            <div class="nomoneyFont" style="padding-top:20px;">{{ $t("points") }}:{{ getBalance }}</div>
            <div class="nomoneyFont">{{ template }}{{ $t("amount") }} : {{ vWorld_balance }}</div>
            <div class="info_text_box inputModalBg">
              <input
                class="moneyinput"
                type="number"
                pattern="[0-9]*"
                min="1"
                v-model="transferPoint"
                @keyup="inputtransferVal()"
              />
            </div>
            <!-- <div v-if="!isNormal_deposit" class="imgfontGreen im" @click="deposit()">
              <span>{{ $t("transferOut") }}</span>
            </div>-->
            <div class="imgfontGreen im" @click="transferOut()">
              <span>{{ $t("transferOut") }}</span>
            </div>
          </div>
          <!-- 轉入 -->
          <div class="modalContentAll" v-else>
            <div
              class="nomoneyFont"
              style="padding-top:20px;"
            >{{ template }}{{ $t("amount") }} : {{ vWorld_balance }}</div>
            <div class="nomoneyFont">{{ $t("gamepublisher") }} : {{vendor_name}}</div>
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
            <div v-if="!isNormal_deposit" class="imgfontGreen im" @click="deposit()">
              <span>{{ $t("transferIn") }}</span>
            </div>
            <div v-if="isNormal_deposit" class="imgfontGreen im" @click="normal_deposit()">
              <span>{{ $t("transferIn") }}</span>
            </div>
          </div>
          <!-- 轉出 -->
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
import '@/assets/js/jquery.min.js'
//import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap/dist/js/bootstrap.js'
import { Slide } from 'vue-burger-menu' // import the CSS transitions you wish to use, in this case we are using `Slide`
import LoginHeader from '@/components/v_world/Login_header'
import { setTimeout, clearTimeout } from 'timers'
import { async, Promise } from 'q'
import { constants } from 'fs'
function playSound() {
  var sound = document.getElementById('audio')
  sound.play()
}
export default {
  components: {
    Slide,
    LoginHeader,
    maskModal
  },

  computed: {
    ...mapGetters([
      'getGameList',
      'getGameTypes',
      'getVendor_balance',
      'getFavoriteLength',
      'getNew_VendorBalance'
    ]),
    getBalance() {
      let vendorPoints = this.getNew_VendorBalance
        ? this.getNew_VendorBalance
        : this.getVendor_balance
      return vendorPoints
    }
  },
  watch: {
    // 即時搜尋 監聽searchKey輸入停止後 0.6秒才執行"searchGame()"，實現input連續輸入，只發一次請求
    searchKey() {
      clearTimeout(this.timeout)
      this.timeout = setTimeout(() => {
        this.searchGame()
      }, 600)
    }
  },

  data() {
    return {
      template: window.sessionStorage.getItem('Template'),
      vWorld_balance: window.sessionStorage.getItem('balance'),
      comfirmVisible: false,
      hide: true,
      // gamelist data
      gameId: '',
      // 搜尋遊戲
      searchKey: '',
      timeout: null,
      //deposit data
      vendor_name: '遊戲商',
      vendorId: '',
      depositVal: 0,
      transferPoint: 0,
      depositVisible: false,
      isNormal_deposit: true,
      transferInOut: ''
    }
  },
  mounted() {
    $('.directionMask').css('display', 'none')
    // console.log(this.getGameList.data, 'gamelist')
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
    // 進遊戲
    playGame(list) {
      this.gameId = list.id
      this.gameName = list.name
      this.vendorId = list.vendor_id

      //   console.log(this.vendorId)
      this.$store.dispatch('actionOne_balance', this.vendorId).then(balance => {
        let vendor_balance = parseInt(balance.toString().replace(',', ''), 10)
        if (vendor_balance < 0) {
          this.depositVisible = true
          this.isNormal_deposit = false
          this.vendor_name = list.vendor_name
        } else {
          //   this.comfirmVisible = true
          this.$router.push('/Game?id=' + this.gameId)
          ga('send', 'event', {
            eventCategory: 'GameList',
            eventAction: 'Game_Click',
            eventLabel: this.gameName,
            eventValue: this.gameId
          })
          sessionStorage.setItem('enterGameName', this.gameName)
          sessionStorage.setItem('enterGameId', this.gameId)

          var sound = document.getElementById('audio')
        }
      })
    },
    // 取單支遊戲商餘額
    getVendorBalance() {
      let vendor_id = sessionStorage.getItem('vendor_id')
      if (vendor_id !== '') {
        this.$store.dispatch('actionOne_balance', vendor_id).then(balance => {
          return balance
        })
      } else {
        this.hide = false
        setTimeout(() => {
          $('.GameListMenu').css('height', 50 + 'px')
          $('.GameListItem').css('padding-top', 50 + 'px')
        }, 100)
      }
    },
    pointTransfer(transferInOut) {
      this.vWorld_balance = window.sessionStorage.getItem('balance')
      //   console.log(this.vWorld_balance, 'balance')
      this.transferInOut = transferInOut
      this.vendor_name = sessionStorage.getItem('vendor_name')
      this.depositVisible = true
      this.isNormal_deposit = true
      if (this.transferInOut == 2) {
        var transferType = '轉出按鈕'
      } else {
        var transferType = '轉入按鈕'
      }
      ga('send', 'event', {
        eventCategory: 'GameList',
        eventAction: 'transferBtn_Click',
        eventLabel: transferType,
        eventValue: this.transferInOut
      })
    },
    comfirm() {
      this.comfirmVisible = false
      this.$router.push('/Game?id=' + this.gameId)
      ga('send', 'event', {
        eventCategory: 'GameList',
        eventAction: 'Game_Click',
        eventLabel: this.gameName,
        eventValue: this.gameId
      })
      sessionStorage.setItem('enterGameName', this.gameName)
      sessionStorage.setItem('enterGameId', this.gameId)
      var sound = document.getElementById('audio')
      sound.play()
    },
    cancel() {
      this.comfirmVisible = false
      var sound = document.getElementById('audio')
      sound.play()
    },
    close() {
      this.depositVisible = false
    },
    // 加到最愛
    favorite(item) {
      let status
      if (item.list.is_favourite == 0) {
        status = 1
        var favouriteType = '加入最愛'
        ga('send', 'event', {
          eventCategory: 'GameList',
          eventAction: 'Favourite_Click',
          eventLabel: favouriteType,
          eventValue: item.list.id
        })
      } else if (item.list.is_favourite == 1) {
        status = 0
        var favouriteType = '取消最愛'
        ga('send', 'event', {
          eventCategory: 'GameList',
          eventAction: 'Favourite_Click',
          eventLabel: favouriteType,
          eventValue: item.list.id
        })
      }
      let data = {
        game_id: item.list.id,
        is_favourite: status,
        i: item.i
      }
      this.$store.dispatch('action_favorite', data)
    },
    // 遊戲列表 //
    getGamelist(data) {
      this.searchKey = ''
      if (data == undefined) {
        sessionStorage.setItem('type_id', '')
      }
      sessionStorage.setItem('personal', '')
      let listData = {
        vendor_id: sessionStorage.getItem('vendor_id'),
        type_id: sessionStorage.getItem('type_id'),
        key: '',
        page: 1,
        per_page: 200
      }
      if (data) {
        // have type
        sessionStorage.setItem('type_id', data)
        listData.type_id = data
      }
      this.$store.dispatch('actionGamelist', listData)
    },
    // 我的最愛列表
    getFavoriteList(personal) {
      sessionStorage.setItem('personal', personal)
      this.searchKey = ''
      let listData = {
        vendor_id: sessionStorage.getItem('vendor_id'),
        personal: personal,
        page: 1,
        per_page: 100
      }
      this.$store.dispatch('action_myGames', listData)
    },
    // 搜尋遊戲
    searchGame() {
      let listData = {
        vendor_id: sessionStorage.getItem('vendor_id'),
        type_id: sessionStorage.getItem('type_id'),
        personal: sessionStorage.getItem('personal'),
        key: this.searchKey,
        page: 1,
        per_page: 100
      }
      this.$store.dispatch('action_myGames', listData)
    },

    /// 餘額不足轉點 //
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
    //轉出input
    inputtransferVal() {
      this.transferPoint = parseInt(this.transferPoint, 10)
      let getPoints = parseInt(this.getBalance.toString().replace(',', ''), 10)
      if (this.transferPoint >= getPoints) {
        this.transferPoint = getPoints
      } else if (!this.transferPoint) {
        this.transferPoint = 0
      }
    },
    // 遊戲餘額不足，點數轉入
    deposit() {
      let data = {}
      data['isDeposit'] = true
      data['vendor_id'] = this.vendorId
      data['point'] = this.depositVal
      data['game_id'] = this.gameId
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    },
    // 一般轉點
    normal_deposit() {
      let data = {}
      data['isDeposit'] = false
      data['vendor_id'] = sessionStorage.getItem('vendor_id')
      data['point'] = this.depositVal
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    },
    //點數轉出
    transferOut() {
      let data = {}
      data['vendor_id'] = sessionStorage.getItem('vendor_id')
      data['point'] = this.transferPoint
      this.$store.dispatch('vendorToBalance', data)
      this.depositVisible = false
      this.vWorld_balance = window.sessionStorage.getItem('balance')
    }
  },
  created() {
    this.depositVal = Math.round(
      parseInt(this.vWorld_balance.toString().replace(',', ''), 10) / 2
    )
    // this.transferPoint = Math.round(
    //   parseInt(this.getBalance.toString().replace(',', ''), 10) / 2
    // )
    this.getGamelist()
    this.getVendorBalance()
    this.$store.dispatch('action_gameTypes')
    this.$store.dispatch(
      'action_favorite_Length',
      sessionStorage.getItem('vendor_id')
    )
  }
}
</script>

<!-- Add "scoped" attribute to limit CSS to this component only -->
<style scoped>
</style>
