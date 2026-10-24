<template>
  <div id="G733">
    <div class="mobileHeader">
      <PointsHeader></PointsHeader>
    </div>
    <div class="single_block pointsPcTop">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2">
          <div class="pointsAll">
            <div class="pointsBtn">
              <div @click="allIn()" class="silverBorder btnBorder_all" style="margin: 15px auto;">
                <div class="yellowBtn btn_all">{{ $t("transferAll") }}</div>
              </div>
              <div
                v-if="!isReload"
                @click="Reload()"
                class="silverBorder btnBorder_all"
                style="margin: 15px auto;"
              >
                <div class="blueBtn btn_all">{{ $t("reorganize") }}</div>
              </div>
              <div v-if="isReload" class="silverBorder btnBorder_all" style="margin: 15px auto;">
                <div class="blueBtnH btn_all">{{ $t("reorganize") }}</div>
              </div>
            </div>
            <div class="clearfix PointsItem">
              <div class="pointSelect float-left" v-for="(vendor, i) in getVendors" :key="i">
                <div
                  class="GamerPoint"
                  :class="vendor.status == 3||vendor.status == 4?'disGray':''"
                  v-if="vendor.status == 1 ||vendor.status == 3||vendor.status == 4"
                >
                  <img src="../../assets/img/G733/pointTrBg.png" />
                  <div class="pointTitle">
                    <div class="pointBg">
                      <img src="../../assets/img/G733/PointMoney.png" />

                      <div class="pointdigital">{{ vendor.balance }}</div>
                    </div>
                    <div class="reBtn" @click="ReVendor(vendor.id)" v-if="vendor.status == 1">
                      <img src="../../assets/img/G733/reBtn.png" />
                    </div>
                    <div class="reBtn" v-if="vendor.status == 3||vendor.status == 4">
                      <img src="../../assets/img/G733/reBtn.png" />
                    </div>
                  </div>
                  <div class="PointsImg">
                    <img :src="vendor.image" />
                  </div>
                  <div class="pointButton clearfix" v-if="vendor.status == 1">
                    <a @click="visitOut(vendor)">
                      <div class="ButtonInOut float-left">
                        <img src="../../assets/img/G733/moneyIn.png" />
                      </div>
                    </a>
                    <a @click="visitIn(vendor)">
                      <div class="ButtonInOut float-right">
                        <img src="../../assets/img/G733/moneyOut.png" />
                      </div>
                    </a>
                  </div>
                  <div class="pointButton clearfix" v-if="vendor.status == 3||vendor.status == 4">
                    <div class="ButtonInOut float-left">
                      <img src="../../assets/img/G733/moneyIn.png" />
                    </div>
                    <div class="ButtonInOut float-right">
                      <img src="../../assets/img/G733/moneyOut.png" />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- 轉出平台彈窗  右邊 -->
      <div class="mask" v-if="visibleIn">
        <div class="PointModalBg">
          <img src="../../assets/img/G733/PointModalBg.png" class="pointMobile" />
          <img src="../../assets/img/G733/PointPcBg.png" class="pointPc" />
          <div class="pointModalTitle">
            {{ $t("transferOut") }}
            <img
              src="../../assets/img/G733/modalClose.png"
              class="pointModalClose"
              @click="close()"
            />
          </div>
          <div class="pointModalInput">
            <div class="pointTO clearfix">
              <div class="float-left pointToFont">{{ template_name }}</div>
              <div class="float-left pointToImg">
                <img src="../../assets/img/G733/pointTO.png" />
              </div>
              <div class="float-left pointToFont">{{ vendor_name }}</div>
            </div>
            <div class="goldBorder btnBorder_all" style="width:65%; margin:auto;">
              <div class="ListborderInput">
                <span class="forgetphoneCode setNewPw">
                  {{
                  $t("points")
                  }}
                </span>
                <input
                  class="blackBtn settingBtn fontsizelogin"
                  style="text-align:right; color:#F3D84D; height:35px;"
                  v-model="points"
                  type="number"
                  pattern="[0-9]*"
                  @keyup="inputvalue('in')"
                />
              </div>
            </div>
          </div>
          <div class="pointModalNumber clearfix">
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(10)"
            >
              <div class="blackBtn settingBtn fontsizelogin pointBtnSize">10</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(30)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">30</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(50)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">50</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(100)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">100</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(300)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">300</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(500)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">500</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(1000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">1000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(3000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">3000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(5000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">5000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(10000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">10000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn(30000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">30000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointIn('max')"
            >
              <div class="blackBtn settingBtn red_Btnbg fontsizelogin pointBtnSize">MAX</div>
            </div>
          </div>
          <div class="pointModalBtn">
            <div class="silverBorder btnBorder_all" style="margin:35px auto;">
              <button type="submit" @click="transferOut()" class="redBtn btn_all">{{ $t("submit") }}</button>
            </div>
          </div>
        </div>
      </div>

      <!-- 轉入平台彈窗 左邊 -->
      <div class="mask" v-if="visibleOut">
        <div class="PointModalBg">
          <img src="../../assets/img/G733/PointModalBg.png" class="pointMobile" />
          <img src="../../assets/img/G733/PointPcBg.png" class="pointPc" />
          <div class="pointModalTitle">
            {{ $t("transferIn") }}
            <img
              src="../../assets/img/G733/modalClose.png"
              class="pointModalClose"
              @click="close()"
            />
          </div>
          <div class="pointModalInput">
            <div class="pointTO clearfix">
              <div class="float-left pointToFont">{{ template_name }}</div>
              <div class="float-left pointToImg">
                <img src="../../assets/img/G733/TOpoint.png" />
              </div>
              <div class="float-left pointToFont">{{ vendor_name }}</div>
            </div>
            <div class="goldBorder btnBorder_all" style="width:65%; margin:auto;">
              <div class="ListborderInput">
                <span class="forgetphoneCode setNewPw">
                  {{
                  $t("points")
                  }}
                </span>
                <input
                  class="blackBtn settingBtn fontsizelogin"
                  style="text-align:right; color:#F3D84D; height:35px;"
                  v-model="points"
                  type="number"
                  pattern="[0-9]*"
                  @keyup="inputvalue('out')"
                />
              </div>
            </div>
          </div>
          <div class="pointModalNumber clearfix">
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(10)"
            >
              <div class="blackBtn settingBtn fontsizelogin pointBtnSize">10</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(30)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">30</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(50)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">50</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(100)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">100</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(300)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">300</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(500)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">500</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(1000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">1000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(3000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">3000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(5000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">5000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(10000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">10000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut(30000)"
            >
              <div class="blackBtn settingBtn point_Pbg fontsizelogin pointBtnSize">30000</div>
            </div>
            <div
              class="grayInput pointbtnBorder_all float-left pointBtnMargin"
              @click="pointOut('max')"
            >
              <div class="blackBtn settingBtn red_Btnbg fontsizelogin pointBtnSize">MAX</div>
            </div>
          </div>
          <div class="pointModalBtn">
            <div class="silverBorder btnBorder_all" style="margin:35px auto;">
              <button type="submit" @click="transferIn()" class="redBtn btn_all">{{ $t("submit") }}</button>
            </div>
          </div>
        </div>
      </div>
      <!-- 915 15秒提示 -->

      <div class="mask" v-if="visibleNote">
        <div class="Popups_Modal">
          <div class="PopupsTitle">{{ $t("prompt") }}</div>
          <div class="PopupsConAll">
            <div class="PopupsCon popupsConFont">
              918KISS限制15秒後
              <br />才可再次轉入轉出
            </div>
            <div class="PopupsBtn">
              <div class="silverBorder btnBorder_all">
                <button type="submit" @click="close()" class="redBtn btn_all">{{ $t("close") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- 全部轉入 -->

      <div class="mask" v-if="visibleAll_In">
        <div class="Popups_Modal">
          <div class="PopupsTitle">{{ $t("prompt") }}</div>
          <div class="PopupsConAll">
            <div class="PopupsCon popupsConFont">
              <div>
                {{ Gamers }} {{ $t("willAllTransferTo") }}
                {{ template_name }}
              </div>
              <div>{{ $t("areYouSureToContinue") }}</div>
            </div>
            <div class="PopupsBtn clearfix">
              <div class="float-left">
                <div class="silverBorder btnBorder_all modalBtn">
                  <button
                    type="submit"
                    @click="transferAll_In()"
                    class="redBtn btn_all"
                  >{{ $t("confirm") }}</button>
                </div>
              </div>
              <div class="float-right">
                <div class="silverBorder btnBorder_all modalBtn">
                  <button
                    type="submit"
                    @click="visibleAll_In = false"
                    class="grayBtn btn_all"
                  >{{ $t("cancel") }}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 轉入轉出完成後彈窗  -->
      <div class="mask" v-if="point_InOut">
        <div class="Popups_Modal">
          <div class="PopupsTitle">{{ $t("prompt") }}</div>
          <div class="PopupsConAll">
            <div class="PopupsCon popupsConFont">{{ $t("turnPointCompleted") }}</div>
            <div class="PopupsBtn clearfix">
              <div class="silverBorder btnBorder_all">
                <button
                  type="submit"
                  @click="pointInOutClose()"
                  class="redBtn btn_all"
                >{{ $t("finish") }}</button>
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
import PointsHeader from '@/components/G733/Points_header'
import LobbyPCHeader from '@/components/G733/Lobby_PCheader'
import '../../assets/scss/G733/style.css'
import '../../assets/scss/G733/css.css'
import { mapGetters, mapActions } from 'vuex'
import { fail } from 'assert'
import { constants, truncate } from 'fs'
import { PassThrough } from 'stream'
import { setTimeout } from 'timers'
import maskModal from './Modal'
export default {
  components: {
    PointsHeader,
    LobbyPCHeader,
    maskModal
  },
  data() {
    return {
      //   vPoints: 0,
      visibleAll_In: false, // 全部轉入窗
      visibleIn: false, // 轉入窗
      visibleOut: false, // 轉出窗
      visibleNote: false, // 15秒提示窗
      repeat: false, // 15秒重複轉點
      isReload: true,
      reloadTimer: 15,
      isReVendor: false,
      point_InOut: false,

      template_name: sessionStorage.getItem('TemplateTitle'),
      vendor_name: '',
      vendor_balance: null,

      points: 0,
      vendor_id: '',
      Gamers: '遊戲商',
      VWorld: 'V-World'
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
    //GA(進頁面)
    this.$ga.event({
      eventCategory: 'Points',
      eventAction: 'Enter',
      eventLabel: 'Points'
    })
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
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

      if (val === 'max') {
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
      if (val == 'max') {
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
      }, 3000)
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
      }, 3000)
      ga('send', 'event', {
        eventCategory: 'Points',
        eventAction: 'TransferOut_Click',
        eventLabel: this.vendor_name,
        eventValue: this.points
      })
    },
    pointInOutClose() {
      sessionStorage.setItem('POINT_OK', 'Clear')
      this.point_InOut = false
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
      this.playSound()
    },
    transferAll_In() {
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
    Reload(id) {
      if (!this.isReload) {
        this.playSound()
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
      this.$router.push('/RecordList')
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
