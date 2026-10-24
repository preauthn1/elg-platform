<template>
  <div class="PCLobby">
    <div class="bgi">
      <LobbyHeader></LobbyHeader>
      <div class="points">
        <div class="pointsBtn">
          <div class="pointBtnOne" @click="allIn()" v-if="transaction_all != 1">
            <img src="../../../static/img/C711_PC/pointsBtn.png" />
            <div class="pointBtnFont">{{ $t("transferAll") }}</div>
          </div>
          <div v-if="!isReload" class="pointBtnOne" @click="Reload()">
            <img src="../../../static/img/C711_PC/pointsBtn.png" />
            <div class="pointBtnFont">{{ $t("reorganize") }}</div>
          </div>
          <div v-if="isReload" class="pointBtnOne">
            <img src="../../../static/img/C711_PC/pointsBtnG.png" />
            <div class="pointBtnFont">{{ $t("reorganize") }} {{reloadTimer}}</div>
          </div>
          <div class="pointBtnOne">
            <img src="../../../static/img/C711_PC/pointsBtn.png" />
            <div class="pointBtnFont" @click="toRecord()">{{$t('recordInquiry')}}</div>
          </div>
        </div>

        <div class="clearfix PointsItem" v-if="transaction_all != 1">
          <div
            class="pointSelect"
            v-for="(vendor, i) in getVendors"
            :key="i"
            v-if="vendor.status == 1||vendor.status == 3 ||vendor.status == 4"
            :class="vendor.status == 3||vendor.status == 4?'disGray':''"
          >
            <div class="GamerPoint">
              <img src="../../../static/img/C711_PC/pointTrBg.png" />
              <div class="pointTitle">
                <div class="pointBg">
                  <div class="ele-border">
                    <div class="ele_border_in gradual-black">{{ vendor.balance }}</div>
                  </div>
                </div>
                <div class="reBtn">
                  <a @click="ReVendor(vendor.id)" v-if="vendor.status == 1">
                    <img src="../../../static/img/C711_PC/reBtn.png" />
                  </a>
                </div>
                <div class="reBtn" v-if="vendor.status == 3||vendor.status == 4">
                  <img src="../../../static/img/C711_PC/reBtn.png" />
                </div>
              </div>
              <div class="PointsImg">
                <img :src="vendor.image" />
              </div>
              <div class="pointButton clearfix" v-if="vendor.status == 1">
                <a @click="visitOut(vendor)">
                  <div class="ButtonInOut float-left">
                    <img src="../../../static/img/C711_PC/moneyIn.png" />
                  </div>
                </a>
                <a @click="visitIn(vendor)">
                  <div class="ButtonInOut float-right">
                    <img src="../../../static/img/C711_PC/moneyOut.png" />
                  </div>
                </a>
              </div>
              <div class="pointButton clearfix" v-if="vendor.status == 3||vendor.status == 4">
                <div class="ButtonInOut float-left">
                  <img src="../../../static/img/C711_PC/moneyIn.png" />
                </div>

                <div class="ButtonInOut float-right">
                  <img src="../../../static/img/C711_PC/moneyOut.png" />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 點數轉出轉入 右 -->
    <div class="half_popUp mask" v-if="visibleIn">
      <div class="wrap">
        <img class="bg" src="/static/img/C711_PC/user@example.invalid" />
        <div class="block">
          <div class="head">
            <p>{{ $t("transferOut") }}</p>
            <img @click="close()" src="/static/img/C711_PC/Group5301.png" />
          </div>
          <div class="body">
            <div class="content">
              <div class="pointModalInput">
                <div class="pointTO clearfix">
                  <div class="float-left pointToFont">{{ template_name }}</div>
                  <div class="float-left pointToImg">
                    <img src="../../../static/img/C711_PC/pointTO.png" />
                  </div>
                  <div class="float-left pointToFont">{{ vendor_name }}</div>
                </div>
                <div class="pointInput">
                  <div class="yellow_borderInput" style="width:75%; margin:auto;">
                    <div class="ListborderInput">
                      <span class="forgetphoneCode setNewPw">{{ $t("points") }}</span>
                      <input
                        class="black_InputAll black_inputbg fontsizelogin"
                        style="text-align:right; color:#F3D84D; height:35px;"
                        @keyup="inputvalue('in')"
                        v-model="points"
                        type="number"
                        pattern="[0-9]*"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div>
              <div class="btn-blue" @click="transferOut()">
                <button>{{ $t("submit") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 點數轉出轉入 左 -->
    <div class="half_popUp mask" v-if="visibleOut">
      <div class="wrap">
        <img class="bg" src="/static/img/C711_PC/user@example.invalid" />
        <div class="block">
          <div class="head">
            <p>{{ $t("transferIn") }}</p>
            <img @click="close()" src="/static/img/C711_PC/Group5301.png" />
          </div>
          <div class="body">
            <div class="content">
              <div class="pointModalInput">
                <div class="pointTO clearfix">
                  <div class="float-left pointToFont">{{ template_name }}</div>
                  <div class="float-left pointToImg">
                    <img src="../../../static/img/C711_PC/TOpoint.png" />
                  </div>
                  <div class="float-left pointToFont">{{ vendor_name }}</div>
                </div>
                <div class="pointInput">
                  <div class="yellow_borderInput" style="width:75%; margin:auto;">
                    <div class="ListborderInput">
                      <span class="forgetphoneCode setNewPw">{{ $t("points") }}</span>
                      <input
                        class="black_InputAll black_inputbg fontsizelogin"
                        style="text-align:right; color:#F3D84D; height:35px;"
                        @keyup="inputvalue('out')"
                        v-model="points"
                        type="number"
                        pattern="[0-9]*"
                      />
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div>
              <div class="btn-blue" @click="transferIn()">
                <button>{{ $t("submit") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 轉入轉出完成後彈窗 -->
    <div class="popUp_block" v-if="point_InOut">
      <div class="wrap">
        <div class="title">{{ $t("prompt") }}</div>
        <div class="content">{{$t('turnPointCompleted')}}</div>
        <div class="modalBtn">
          <div class="btn-blue">
            <button @click="point_InOut=false">{{ $t("confirm") }}</button>
          </div>
        </div>
      </div>
    </div>
    <!-- 全部轉入 -->
    <div class="popUp_block" v-if="visibleAll_In">
      <div class="wrap">
        <div class="title">{{ $t("prompt") }}</div>
        <div class="content">
          <div>{{Gamers}} {{ $t("willAllTransferTo") }} {{template_name}}</div>
          <div>{{ $t("areYouSureToContinue") }}</div>
        </div>
        <div class="modalBtn">
          <div class="btn-blue">
            <button @click="transferAll_In()">{{ $t("confirm") }}</button>
          </div>
          <div class="btn-orange">
            <button @click="visibleAll_In = false">{{ $t("cancel") }}</button>
          </div>
        </div>
      </div>
    </div>
    <PopUp></PopUp>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LobbyHeader from '@/components/C711_PC/Lobby_header'
import PopUp from './Pop_up'
// import '../../assets/scss/C711_PC/css.css'
import { fail } from 'assert'
import { constants, truncate } from 'fs'
import { PassThrough } from 'stream'
import { setTimeout } from 'timers'
export default {
  components: {
    LobbyHeader,
    PopUp
  },
  data() {
    return {
      visibleAll_In: false, // 全部轉入窗
      visibleIn: false, // 轉出窗
      visibleOut: false, // 轉入窗
      visibleNote: false, // 15秒提示窗
      repeat: false, // 15秒重複轉點
      isReload: true,
      reloadTimer: 15,
      isReVendor: false,
      point_InOut: false,
      ////
      template_name: sessionStorage.getItem('TemplateTitle'),
      vendor_name: '',
      vendor_balance: null,
      // action data ///
      points: 0,
      vendor_id: '',
      Gamers: '遊戲商',
      VWorld: 'V-World',  
			transaction_all: sessionStorage.getItem("transaction_all"),

    }
  },
  computed: {
    ...mapGetters(['getVendors', 'getNewvpoints']),
    getPoint() {
      let vpoints = this.getNewvpoints
        ? this.getNewvpoints
        : sessionStorage.getItem('balance')
      return vpoints
    }
  },
  mounted() {
    this.$ga.event({
      eventCategory: 'Points',
      eventAction: 'Enter',
      eventLabel: 'Points'
      //   eventValue: 123
    })
  },

  methods: {
    // 轉入轉出彈窗 //
    visitIn(vendor) {
      this.points = 0
      this.visibleIn = true
      this.vendor_id = vendor.id
      this.vendor_name = vendor.name
    },
    visitOut(vendor) {
      this.points = 0
      this.visibleOut = true
      this.vendor_id = vendor.id
      this.vendor_name = vendor.name
      this.vendor_balance = vendor.balance
    },
    close() {
      this.visibleIn = false
      this.visibleOut = false
      this.visibleNote = false
    },
    // 轉入 IN  點擊點數 ///
    pointIn(val) {
      let points = this.points
      let vPoints = parseInt(
        sessionStorage
          .getItem('balance')
          .toString()
          .replace(',', ''),
        10
      )

      if (val == 'max') {
        this.points = vPoints
      } else {
        this.points = points + val
        if (this.points >= vPoints) {
          this.points = vPoints
        }
      }
    },
    // 轉出 OUT  點擊點數 //
    pointOut(val) {
      let points = this.points
      let vendorBalance = parseInt(
        this.vendor_balance.toString().replace(',', ''),
        10
      )
      if (val === 'max') {
        this.points = vendorBalance
      } else {
        this.points = points + val
        if (this.points >= vendorBalance) {
          this.points = vendorBalance
        }
      }
    },
    // input 輸入判斷
    inputvalue(val) {
      if (val === 'in') {
        // 平台點數去除千分位  ex: 1,000.00 > 1000
        let vPoints = parseInt(
          sessionStorage
            .getItem('balance')
            .toString()
            .replace(',', ''),
          10
        )
        if (this.points == '') {
          // input 空值'' = 0
          this.points = 0
        } else if (this.points !== '') {
          // ex: 0500 > 500 開頭去0
          this.points = parseInt(this.points)
        }
        if (this.points >= vPoints) {
          // input 轉入最多 = 平台點數
          this.points = vPoints
        }
      } else if (val === 'out') {
        let vendorBalance = parseInt(
          this.vendor_balance.toString().replace(',', ''),
          10
        )
        if (this.points == '') {
          this.points = 0
        } else if (this.points !== '') {
          this.points = parseInt(this.points)
        }
        if (this.points >= vendorBalance) {
          // input 轉出最多 = 遊戲商點數
          this.points = vendorBalance
        }
      }
    },
    // 點數 轉入轉出
    //平台轉入
    transferIn() {
      this.visibleOut = false
      let data = {}
      data['vendor_id'] = this.vendor_id
      data['vendor_name'] = this.vendor_name
      data['point'] = this.points
      if (this.vendor_id == '3') {
        if (this.repeat == false) {
          this.repeat = true // 防15秒
          this.$store.dispatch('vendorToBalance', data)
          setTimeout(() => {
            this.repeat = false
          }, 15000)
        } else if (this.repeat == true) {
          this.visibleNote = true
        }
      } else {
        this.$store.dispatch('vendorToBalance', data)
      }

      setTimeout(() => {
        if (sessionStorage.getItem('POINT_OK') == 'OK') {
          this.point_InOut = true
        }
      }, 1000)
      ga('send', 'event', {
        eventCategory: 'Points',
        eventAction: 'TransferIn_Click',
        eventLabel: this.vendor_name,
        eventValue: this.points
      })
    },
    //平台轉出
    transferOut() {
      this.visibleIn = false
      let data = {}
      data['vendor_id'] = this.vendor_id
      data['vendor_name'] = this.vendor_name
      data['point'] = this.points
      if (this.vendor_id == '3') {
        if (this.repeat == false) {
          this.repeat = true // 防15秒
          this.$store.dispatch('balanceToVendor', data)
          setTimeout(() => {
            this.repeat = false
          }, 15000)
        } else if (this.repeat == true) {
          this.visibleNote = true
        }
      } else {
        this.$store.dispatch('balanceToVendor', data)
      }

      setTimeout(() => {
        if (sessionStorage.getItem('POINT_OK') == 'OK') {
          this.point_InOut = true
        }
      }, 1000)
      ga('send', 'event', {
        eventCategory: 'Points',
        eventAction: 'TransferOut_Click',
        eventLabel: this.vendor_name,
        eventValue: this.points
      })
    },
    // 單筆金額重整
    ReVendor(id) {
      if (!this.isReVendor) {
        this.$store.dispatch('actionVendor_balance', id)
        this.isReVendor = true
        setTimeout(() => {
          this.isReVendor = false
        }, 11000)
      }
    },
    // 全部轉入
    allIn() {
      this.visibleAll_In = true
    },
    transferAll_In() {
      //   var idList = this.getVendors

      var vendors = this.getVendors
      var store = this.$store
      var visibleAll_In = this.visibleAll_In
      Object.keys(this.getVendors).forEach(function(i, index, array) {
        let vendor_id = vendors[i].id
        let balance = parseInt(
          vendors[i].balance.toString().replace(',', ''),
          10
        )
        if (balance > 0) {
          let data = {
            vendor_id: vendor_id,
            point: balance
          }
          store.dispatch('vendorToBalance', data)
          ga('send', 'event', {
            eventCategory: 'Points',
            eventAction: 'TransferAll_Click'
          })
          visibleAll_In = false
        }
      })

      this.visibleAll_In = false
    },
    // 全部重整
    Reload() {
      if (!this.isReload) {
        window.location.reload()
      }
    },
    Reloading() {
      let t = setInterval(() => {
        this.reloadTimer--
        if (this.reloadTimer == 0) {
          clearInterval(t)
          this.isReload = false
        }
      }, 1000)
    },
    toRecord() {
      this.$router.push('/Record')
    }
  },
  created() {
    //   類型(預設1)1:遊戲列表，2:轉點列表
    let data = {
      type: 2,
      isGetBalance: true
    }
    this.$store.dispatch('actionVendors', data)
    this.Reloading()
  }
}
</script>