<template>
  <div class="footerBtn BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="PointsPadding">
      <div class="pointsfixed BgContent">
        <div class="PointsAcc">
          <div class="textbox">
            {{ $t("points") }}:
            {{ getPoint }}
            <a
              href="/StoredValue"
              onclick="ga('send', 'event', 'LobbyHeader', 'StoredBtn_Click', 'Stored');"
            >
              <img src="../../assets/img/v_world/Gadd.png" class="float-right" />
            </a>
          </div>
        </div>
        <div class="ButtonSelect clearfix">
          <div class="float-left AllButton greenBtn" @click="allIn()">
            <span>{{ $t("transferAll") }}</span>
          </div>
          <div v-if="!isReload" class="float-right AllButton blue2Btn" @click="Reload()">
            <span>{{ $t("reorganize") }}</span>
          </div>
          <div v-if="isReload" class="float-right AllButton btnLock">
            <span>{{ $t("reorganize") }} {{reloadTimer}}</span>
          </div>
          <!-- <div class="float-left ReButton">
          <span>{{ $t("reorganize") }}</span>
        <button type="button" class="btn btn-primary" style="width:95%;">{{ $t("reorganize") }}</button>
          </div>-->
        </div>
      </div>

      <div class="PointsItem clearfix">
        <div v-for="(vendor, i) in getVendors" :key="i" class="GameSelect float-left">
          <div v-if="vendor.status == 1" class="GameAll">
            <div class="PointsTop clearfix">
              <div class="float-left GamePoints">{{ vendor.balance }}</div>
              <div class="float-left PointsReBtn">
                <a @click="ReVendor(vendor.id)">
                  <div class="reBtn"></div>
                </a>
              </div>
            </div>
            <div class="PointsImg">
              <img src="../../assets/img/v_world/blackBg.png" class="blackBgPic" />
              <div class="blackBg">
                <img :src="vendor.image" />
              </div>
            </div>
            <div class="PointsButton clearfix">
              <div class="ButtonInOut float-left">
                <a @click="visitOut(vendor)">
                  <img src="../../assets/img/v_world/in_btn_normal.png" class="inoutBtn" />
                </a>
              </div>

              <div class="ButtonInOut float-left">
                <a @click="visitIn(vendor)">
                  <img src="../../assets/img/v_world/out_btn_normal.png" class="inoutBtn" />
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- 轉入彈窗  -->

      <div v-if="visibleIn" class="mask StoredModal">
        <div class="StoredModalCon">
          <div class="StoredTitile">
            <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
            <div class="TitleFontClose TitleFontPoint clearfix">
              {{ $t("transferOut") }}
              <div class="TitleClose">
                <img @click="close()" src="../../assets/img/v_world/closeBtn.png" class="close" />
              </div>
            </div>
          </div>
          <div class="StoredContent">
            <div class="pointTo clearfix">
              <div class="float-left pointToFont">{{ template_name }}</div>
              <div class="float-left pointToImg">
                <img src="../../assets/img/v_world/toR.png" />
              </div>
              <div class="float-left pointToFont">{{ vendor_name }}</div>
            </div>
            <div class="pointAcc info_text_box clearfix">
              <div class="pointInputAll clearfix">
                <div class="float-left">
                  <span>{{ $t("points") }}</span>
                </div>
                <div class="float-right">
                  <input
                    @keyup="inputvalue('in')"
                    v-model="points"
                    type="number"
                    pattern="[0-9]*"
                    class="pointsInput"
                  />
                </div>
              </div>
            </div>
            <div class="pointsBtn ContentBgFont">
              <div class="BtnAll clearfix">
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(10)">10</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(30)">30</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(50)">50</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(100)">100</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(300)">300</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(500)">500</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(1000)">1000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(3000)">3000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(5000)">5000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(10000)">10000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointIn(30000)">30000</div>
                <div class="float-left numberBtn pointsredBtn" @click="pointIn('max')">MAX</div>
              </div>
            </div>
            <div class="StoredModalBtn">
              <div class="Gimgfont greenBtn" @click="transferOut()">
                <span>{{ $t("submit") }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 轉出彈窗 -->
      <div v-if="visibleOut" class="mask StoredModal">
        <div class="StoredModalCon">
          <div class="StoredTitile">
            <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
            <div class="TitleFontClose TitleFontPoint clearfix">
              {{ $t("transferIn") }}
              <div class="TitleClose">
                <img @click="close()" src="../../assets/img/v_world/closeBtn.png" class="close" />
              </div>
            </div>
          </div>
          <div class="StoredContent">
            <div class="pointTo clearfix">
              <div class="float-left pointToFont">{{ template_name }}</div>
              <div class="float-left pointToImg">
                <img src="../../assets/img/v_world/toL.png" />
              </div>
              <div class="float-left pointToFont">{{ vendor_name }}</div>
            </div>

            <div class="pointAcc info_text_box clearfix">
              <div class="pointInputAll clearfix">
                <div class="float-left">
                  <span>{{ $t("points") }}</span>
                </div>
                <div class="float-right">
                  <input
                    @keyup="inputvalue('out')"
                    v-model="points"
                    type="number"
                    pattern="[0-9]*"
                    class="pointsInput"
                  />
                </div>
              </div>
            </div>
            <div class="pointsBtn ContentBgFont">
              <div class="BtnAll clearfix">
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(10)">10</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(30)">30</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(50)">50</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(100)">100</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(300)">300</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(500)">500</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(1000)">1000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(3000)">3000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(5000)">5000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(10000)">10000</div>
                <div class="float-left numberBtn pointsbulBtn" @click="pointOut(30000)">30000</div>
                <div class="float-left numberBtn pointsredBtn" @click="pointOut('max')">MAX</div>
              </div>
            </div>
            <div class="StoredModalBtn">
              <div class="Gimgfont greenBtn" @click="transferIn()">
                <span>{{ $t("submit") }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 918 15秒提示窗 -->
      <div v-if="visibleNote" class="mask">
        <div class="modalBlueAll">
          <div>918KISS限制15秒後才可再次轉入轉出</div>
          <div>
            <button @click="close()" type="button" class="btn btn-secondary">{{ $t("close") }}</button>
          </div>
        </div>
      </div>
      <!-- 全部轉入 -->
      <div v-if="visibleAll_In" class="mask">
        <div class="modalBlueAll">
          <p>
            {{Gamers}} {{ $t("willAllTransferTo") }} {{VWorld}}
            <br />
            {{ $t("areYouSureToContinue") }}
          </p>
          <div class="comfirmBtnAll pointsModal">
            <div class="float-left greenBtn modalBtn" @click="transferAll_In()">{{ $t("confirm") }}</div>
            <div
              class="float-right redBtn modalBtn"
              @click="visibleAll_In = false"
            >{{ $t("cancel") }}</div>
          </div>
          <!-- <button @click="transferAll_In()" class="btn btn-secondary">確定</button>
          <button @click="visibleAll_In = false" class="btn btn-secondary">取消</button>-->
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>

<script>
import LoginHeader from '@/components/v_world/Login_header'
import { mapGetters, mapActions } from 'vuex'
import maskModal from './Modal'
import { fail } from 'assert'
import { constants, truncate } from 'fs'
import { PassThrough } from 'stream'
import { setTimeout } from 'timers'
export default {
  components: {
    LoginHeader,
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
      ////
      template_name: sessionStorage.getItem('Template'),
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
      //   console.log(vendor)
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
      //   console.log('in', vPoints)
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
      this.playSound()
    },
    transferAll_In() {
      //   var idList = this.getVendors
      //   console.log(this.getVendors, 'gettttt')
      var vendors = this.getVendors
      var store = this.$store
      var visibleAll_In = this.visibleAll_In
      Object.keys(this.getVendors).forEach(function(i, index, array) {
        let vendor_id = vendors[i].id
        let balance = parseInt(
          vendors[i].balance.toString().replace(',', ''),
          10
        )
        if (balance !== 0) {
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

      //   let len = Object.keys(this.getVendors).length //取物件長
      //   console.log(len, 'lllen')
      //   for (let i = 1; i <= len; i++) {
      //     console.log(i, 'iii')
      //     let vendor_id = this.getVendors[i].id
      //     let balance = parseInt(
      //       this.getVendors[i].balance.toString().replace(',', ''),
      //       10
      //     )
      //     if (balance !== 0) {
      //       let data = {
      //         vendor_id: vendor_id,
      //         point: balance
      //       }
      //       this.$store.dispatch('vendorToBalance', data)
      //       ga('send', 'event', {
      //         eventCategory: 'Points',
      //         eventAction: 'transferAll_Click'
      //       })
      //       this.visibleAll_In = false
      //     }
      //   }
      this.visibleAll_In = false
    },
    // 全部重整
    Reload() {
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
    // this.vPoints = parseInt(
    //   sessionStorage
    //     .getItem('balance')
    //     .toString()
    //     .replace(',', ''),
    //   10
    // )
    let data = {
      type: 2,
      isGetBalance: true
    }
    this.$store.dispatch('actionVendors', data)
    this.Reloading()
  }
}
</script>

<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
.btnLock {
  background-color: #aaa;
  border-radius: 5px;
  cursor: pointer;
  box-shadow: 0 10px 8px -3px #202020;
  background-image: linear-gradient(#ccc, #6e6e6e, #464646);
}
</style>
