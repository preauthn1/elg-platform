<template>
  <div id="C711Mobile">
    <div class="pageBg CwidthHeight widthHeightvh fontFamily">
      <LoginHeader></LoginHeader>
      <div class="pageCon">
        <!-- 維護訊息 -->
        <!-- <div class="up225">
          <div class="upfont">
            <div class="title">NCpay系统维护</div>
            <div class="con">26/02/2020 （星期三）NCpay系统维护（马来西亚时间10:00AM~05:00PM） 对于给您带来的任何不便，我们深表歉意。谢谢</div>
            <div class="title">NCpay System maintenance</div>
            <div class="con">
              26/02/2020 (
              Wednesday) NCpay System maintenance (Malaysia Time10:00AM~05:00PM ) We apologized for any inconvenience. Thank You
            </div>
          </div>
        </div>-->
        <!-- 點數 -->
        <div style="margin-top:10px;">{{ $t("points") }}</div>
        <div class="pointFont">{{ vPoints }}</div>

        <!-- 選擇支付方式 -->
        <div class="yellow_borderInput bankPic">
          <b-form-select v-model="selected" class="black_InputAll black_inputbg blackInput">
            <option :value="null" class="gray_dropdownBg">{{$t("pleaseSelectABank")}}</option>
            <option
              v-for="(item, i) in getOrderTypes"
              :key="i"
              :value="item"
              class="gray_dropdownBg"
            >{{ item.name }}</option>
          </b-form-select>
          <div class="PicD">
            <img src="../../assets/img/C711_Mobile/D.png" />
          </div>
        </div>
        <!-- 輸入框 -->
        <div class="inputvalidate">
          <div class="form-group ListborderInput">
            <input
              class="form-control ListInputAllL Listinputbg"
              id="addBalance"
              v-model="addBalance"
              pattern="[0-9]*"
              type="number"
              :placeholder="$t('pleaseEnterTheNumberOfPoints')"
              onkeyup="value=value.replace(/[^\d]/g,'') "
            />
            <img
              src="../../assets/img/C711_Mobile/grayClose.png"
              @click="resetaddBalance()"
              class="inputClose"
            />
          </div>
          <label
            v-show="errors.has('userId')"
            for="userId"
            class="validateDiv"
          >{{ errors.first("userId") }}</label>
        </div>

        <!-- 按鈕 -->
        <div class="btn-blue">
          <button
            type="submit"
            @click="storedConfirm()"
            style="padding:9px auto;"
          >{{ $t("submit") }}</button>
        </div>
        <!-- 單一錢包訊息 -->
        <div class="oneWalletPrompt" v-if="transaction_all==1">
          <div>1. {{$t('waitForTradingResults')}}</div>
          <div>2. {{$t('afterTheTransaction')}}</div>
        </div>
        <!-- 未輸入彈窗 -->
        <div class="mask" v-if="valueVisible">
          <div class="Popups_Modal">
            <div class="PopupsTitle">{{ $t("prompt") }}</div>
            <div class="PopupsConAll">
              <div class="PopupsCon popupsConFont">
                <p v-if="this.selected == null">{{ $t("pleaseSelectABank") }}</p>
                <p v-if="this.addBalance == 0">{{ $t("pleaseEnterTheNumberOfPoints") }}</p>
              </div>
              <div class="PopupsBtn">
                <div class="btn-blue">
                  <button
                    type="submit"
                    @click="errorClose()"
                    style="padding:9px auto;"
                  >{{ $t("confirm") }}</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- 確認彈窗 -->
        <div class="mask" v-if="visiable">
          <div class="Popups_Modal">
            <div class="PopupsTitle">
              {{ $t("confirmDepositInformation") }}
              <img
                src="../../assets/img/C711_Mobile/modalClose.png"
                class="modalClose"
                @click="close()"
              />
            </div>
            <div class="PopupsConAll">
              <div class="PopupsCon popupsBank">
                <div class="StoredValueBank">
                  <div class="Bank">{{ $t("bankName") }}:</div>
                  <div class="BankName">{{ name }}</div>
                  <div class="Bank">{{ $t("storedValuPoints") }}:</div>
                  <div class="BankName">{{ addBalance }}</div>
                </div>
              </div>
              <div class="PopupsBtn">
                <div class="btn-blue">
                  <button
                    type="submit"
                    @click=" playSound();stored(); gaData(); "
                    style="padding:9px auto;"
                  >{{ $t("confirm") }}</button>
                </div>
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
import LoginHeader from '@/components/C711_Mobile/Login_header'
import { mapGetters, mapActions } from 'vuex'
import { constants } from 'zlib'
import maskModal from './Modal'

export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getOrderTypes', 'getOrderId'])
  },
  data() {
    return {
      visiable: false,
      valueVisible: false,
      vPoints: window.sessionStorage.getItem('balance'),
      selected: null,
      addBalance: '',
      name: '',
      ordertypeId: '',
      orderId: '',
      //   判斷是否自動帶點單一錢包
      transaction_all: sessionStorage.getItem('transaction_all')
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
  },
  methods: {
    //   按鈕音效
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    // 清除點數輸入框
    resetaddBalance() {
      this.addBalance = ''
    },
    //儲值確認談窗
    storedConfirm() {
      var pointMondy = document.getElementById('addBalance').value
      if (this.addBalance == 0 || this.selected == null) {
        this.valueVisible = true
      } else {
        this.visiable = true

        this.name = this.selected.name
        this.ordertypeId = this.selected.id
      }
    },
    close() {
      this.visiable = false
    },
    errorClose() {
      this.valueVisible = false
    },
    //儲值送出
    stored() {
      let obj = {}
      obj['ordertypeId'] = this.ordertypeId
      obj['amount'] = this.addBalance
      this.$store.dispatch('actionAddPoint', obj)
      this.visiable = false
    },
    gaData() {
      setTimeout(() => {
        ga('require', 'ecommerce', 'ecommerce.js')
        // 收集結帳商品資料用
        let getOrderID = this.getOrderId.replace(/_/g, '')

        ga('ecommerce:addItem', {
          id: getOrderID, // 商品ID，必填
          name: this.name, // 商品名稱，必填
          sku: this.ordertypeId, // SKU/code
          category: 'StoredValue', // 分類
          price: this.addBalance, // 商品單價，必填
          quantity: 1 // 數量，必填
        })
        //   收集最終結帳交易資料用
        ga('ecommerce:addTransaction', {
          id: this.ordertypeId, // 交易ID，必填
          affiliation: this.name, // 聯盟或分店
          revenue: this.addBalance // 結帳金額
          // shipping: ,// 運費
          // tax: ,// 稅金
        })
        ga('ecommerce:send')
      }, 1000)
    }
  },

  created() {
    this.$store.dispatch('actionOrderTypes')
    this.$store.dispatch('actionMember')
  }
}
</script>
