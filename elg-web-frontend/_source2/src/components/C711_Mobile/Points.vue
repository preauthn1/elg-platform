<template>
  <div id="C711Mobile">
    <div class="CwidthHeight widthHeightvh fontFamily">
      <div class="pointHeight">
        <PointsHeader></PointsHeader>
      </div>
      <div class="LobbyBgPoints LobbySetting">
        <div class="pageFull LobbyBgPoints">
          <div class="btnFixedBg LobbyBgPoints">
            <div class="pointBtn clearfix">
              <!-- 全部轉入按鈕 -->
              <div class="pointBtnLeftRight float-left" @click="allIn()">
                <img src="../../assets/img/C711_Mobile/PointBtn.png" />
                <div class="pointBtnBg">{{ $t("transferAll") }}</div>
              </div>
              <!-- 重新整理 -->
              <div v-if="!isReload" class="pointBtnLeftRight float-right" @click="Reload()">
                <img src="../../assets/img/C711_Mobile/PointBtn.png" />
                <div class="pointBtnBg">{{ $t("reorganize") }}</div>
              </div>
              <!-- 重新整理倒數 -->
              <div v-if="isReload" class="pointBtnLeftRight float-right">
                <img src="../../assets/img/C711_Mobile/PointBtnG.png" />
                <div class="pointBtnBg">{{ $t("reorganize") }} {{ reloadTimer }}</div>
              </div>
            </div>
          </div>
          <div class="clearfix PointsItem">
            <div class="pointSelect float-left" v-for="(vendor, i) in getVendors" :key="i">
              <div
                class="GamerPoint"
                :class="vendor.status == 3||vendor.status == 4?'disGray':''"
                v-if="vendor.status == 1||vendor.status == 3 ||vendor.status == 4"
              >
                <img src="../../assets/img/C711_Mobile/pointTrBg.png" />

                <div class="pointTitle">
                  <div class="pointBg">
                    <img src="../../assets/img/C711_Mobile/PointMoney.png" />

                    <div class="pointdigital">{{ vendor.balance }}</div>
                  </div>
                  <div class="reBtn" @click="ReVendor(vendor.id)" v-if="vendor.status == 1">
                    <img src="../../assets/img/C711_Mobile/reBtn.png" />
                  </div>
                  <div class="reBtn" v-if="vendor.status == 3||vendor.status == 4">
                    <img src="../../assets/img/C711_Mobile/reBtn.png" />
                  </div>
                </div>
                <div class="PointsImg">
                  <img :src="vendor.image" />
                </div>
                <div class="pointButton clearfix" v-if="vendor.status == 1">
                  <a @click="visitOut(vendor)">
                    <div class="ButtonInOut float-left">
                      <img src="../../assets/img/C711_Mobile/moneyIn.png" />
                    </div>
                  </a>
                  <a @click="visitIn(vendor)">
                    <div class="ButtonInOut float-right">
                      <img src="../../assets/img/C711_Mobile/moneyOut.png" />
                    </div>
                  </a>
                </div>
                <div class="pointButton clearfix" v-if="vendor.status == 3||vendor.status == 4">
                  <div class="ButtonInOut float-left">
                    <img src="../../assets/img/C711_Mobile/moneyIn.png" />
                  </div>

                  <div class="ButtonInOut float-right">
                    <img src="../../assets/img/C711_Mobile/moneyOut.png" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- 轉出平台彈窗  左邊-->
      <div class="mask" v-if="visibleIn">
        <div class="PointModalBg">
          <img src="../../assets/img/C711_Mobile/PointModalBg.png" />
          <div class="pointModalTitle">
            {{ $t("transferOut") }}
            <img
              src="../../assets/img/C711_Mobile/modalClose.png"
              class="pointModalClose"
              @click="close()"
            />
          </div>
          <div class="pointModalInput">
            <div class="pointTO clearfix">
              <div class="float-left pointToFont">{{ template_name }}</div>
              <div class="float-left pointToImg">
                <img src="../../assets/img/C711_Mobile/pointTO.png" />
              </div>
              <div class="float-left pointToFont">{{ vendor_name }}</div>
            </div>
            <div class="yellow_borderInput" style="width:75%; margin:auto;">
              <div class="form-group ListborderInput">
                <span class="forgetphoneCode setNewPw">
                  {{
                  $t("points")
                  }}
                </span>
                <input
                  class="form-control black_InputAll black_inputbg fontsizelogin"
                  style="text-align:right; color:#F3D84D; height:35px;"
                  v-model="points"
                  type="number"
                  pattern="[0-9]*"
                  @keyup="inputvalue('in')"
                />
              </div>
              <!--  -->
            </div>
          </div>
          <div class="pointModalNumber clearfix">
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(10)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">10</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(30)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">30</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(50)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">50</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(100)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">100</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(300)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">300</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(500)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">500</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(1000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">1000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(3000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">3000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(5000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">5000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(10000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">10000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn(30000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">30000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointIn('max')">
              <div class="form-control black_InputAll red_Btnbg fontsizelogin pointBtnSize">MAX</div>
            </div>
          </div>
          <div class="pointModalBtn">
            <div class="btn-blue" style="margin:30px auto;">
              <button
                type="submit"
                @click="transferOut()"
                style="padding:9px auto;"
              >{{ $t("submit") }}</button>
            </div>
          </div>
        </div>
      </div>
      <!-- 轉入平台彈窗 右邊 v-if="visibleOut"-->
      <div class="mask" v-if="visibleOut">
        <div class="PointModalBg">
          <img src="../../assets/img/C711_Mobile/PointModalBg.png" />
          <div class="pointModalTitle">
            {{ $t("transferIn") }}
            <img
              src="../../assets/img/C711_Mobile/modalClose.png"
              class="pointModalClose"
              @click="close()"
            />
          </div>
          <div class="pointModalInput">
            <div class="pointTO clearfix">
              <div class="float-left pointToFont">{{ template_name }}</div>
              <div class="float-left pointToImg">
                <img src="../../assets/img/C711_Mobile/TOpoint.png" />
              </div>
              <div class="float-left pointToFont">{{ vendor_name }}</div>
            </div>
            <div class="yellow_borderInput" style="width:75%; margin:auto;">
              <div class="form-group ListborderInput">
                <span class="forgetphoneCode setNewPw">
                  {{
                  $t("points")
                  }}
                </span>
                <input
                  class="form-control black_InputAll black_inputbg fontsizelogin"
                  style="text-align:right; color:#F3D84D; height:35px;"
                  v-model="points"
                  type="number"
                  pattern="[0-9]*"
                  @keyup="inputvalue('out')"
                />
                <!--  -->
              </div>
            </div>
          </div>
          <div class="pointModalNumber clearfix">
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(10)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">10</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(30)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">30</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(50)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">50</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(100)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">100</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(300)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">300</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(500)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">500</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(1000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">1000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(3000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">3000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(5000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">5000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(10000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">10000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut(30000)">
              <div class="form-control black_InputAll point_Pbg fontsizelogin pointBtnSize">30000</div>
            </div>
            <div class="yellow_borderInput float-left pointBtnMargin" @click="pointOut('max')">
              <div class="form-control black_InputAll red_Btnbg fontsizelogin pointBtnSize">MAX</div>
            </div>
          </div>
          <div class="pointModalBtn">
            <div class="btn-blue" style="margin:30px auto;">
              <button
                type="submit"
                @click="transferIn()"
                style="padding:9px auto;"
              >{{ $t("submit") }}</button>
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
              <div class="btn-orange">
                <button type="submit" @click="close()" style="padding:9px auto;">{{ $t("close") }}</button>
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
                <div class="btn-blueTwo">
                  <button
                    type="submit"
                    @click="transferAll_In()"
                    style="padding:9px auto;"
                  >{{ $t("confirm") }}</button>
                </div>
              </div>
              <div class="float-right">
                <div class="btn-orangeTwo">
                  <button
                    type="submit"
                    @click="visibleAll_In = false"
                    style="padding:9px auto;"
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
              <div class="btn-blueTwo">
                <button
                  type="submit"
                  @click="pointInOutClose()"
                  style="padding:9px auto;"
                >{{ $t("confirm") }}</button>
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
import PointsHeader from '@/components/C711_Mobile/Points_header'
import { mapGetters, mapActions } from 'vuex'
import { fail } from 'assert'
import { constants, truncate } from 'fs'
import { PassThrough } from 'stream'
import { setTimeout } from 'timers'
import maskModal from './Modal'
export default {
  components: {
    PointsHeader,
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
      ////
      template_name: sessionStorage.getItem('TemplateTitle'),
      vendor_name: '',
      vendor_balance: null,
      // action data ///
      points: 0,
      vendor_id: '',
      Gamers: '遊戲商',
      VWorld: 'V-World'
    }
  },

  computed: {
    ...mapGetters(['getVendors', 'getNewvpoints']),
    getPoint() {
      // 轉點後會取得 getNewvpoints 以更新平台點數
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
      //   eventValue: 123
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
      // api回來的balance皆為字串且含有千分位逗號，故把字串replace去掉逗號，parseInt十進位取整數
      // ex: 1,000.00 = 1000
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
          // input 防呆禁止輸入空值 最小為0
          this.points = 0
        } else if (this.points !== '') {
          // 把API回來的金額小數點拿掉
          this.points = parseInt(this.points)
        }
        if (this.points >= vendorBalance) {
          // input防呆 轉出最多=遊戲商點數 不得大於
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
