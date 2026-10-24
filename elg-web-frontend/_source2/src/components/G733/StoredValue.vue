<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>
    <div class="single_block">
      <div class="LoginMain footerBot">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin"></div>

            <div class="loginInfo">
              <!-- 點數 -->
              <div style=" color:#e9b10b;margin-top:10px;">{{ $t("points") }}:{{ vPoints }}</div>

              <div class="info clearfix">
                <b-form-select v-model="selected" class="yellowInput input_all">
                  <option :value="null" class="gray_dropdownBg">
                    {{
                    $t("pleaseSelectABank")
                    }}
                  </option>
                  <option
                    v-for="(item, i) in getOrderTypes"
                    :key="i"
                    :value="item"
                    class="gray_dropdownBg"
                  >{{ item.name }}</option>
                </b-form-select>
                <!-- 輸入框 -->
                <div class="inputvalidate">
                  <div class="info clearfix">
                    <input
                      class="yellowInput input_all"
                      id="addBalance"
                      v-model="addBalance"
                      pattern="[0-9]*"
                      type="number"
                      :placeholder="$t('pleaseEnterTheNumberOfPoints')"
                      onkeyup="value=value.replace(/[^\d]/g,'') "
                    />
                  </div>
                  <label
                    v-show="errors.has('userId')"
                    for="userId"
                    class="validateDiv"
                  >{{ errors.first("userId") }}</label>
                </div>

                <!-- 按鈕 -->
                <div class="silverBorder btnBorder_all">
                  <button
                    type="submit"
                    @click="storedConfirm()"
                    class="redBtn btn_all"
                  >{{ $t("submit") }}</button>
                </div>
                <!-- 單一錢包訊息 -->
                <div class="oneWalletPrompt" v-if="transaction_all==1">
                  <div>1. {{$t('waitForTradingResults')}}</div>
                  <div>2. {{$t('afterTheTransaction')}}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
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
          <div class="PopupsBtn clearfix">
            <div class="silverBorder btnBorder_all">
              <button type="submit" @click="errorClose()" class="redBtn btn_all">{{ $t("finish") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 轉入轉出完成後彈窗  -->
    <div class="mask" v-if="visiable">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{ $t("prompt") }}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div class="StoredValueBank">
              <div class="Bank">{{ $t("bankName") }}:</div>
              <div class="BankName">{{ name }}</div>
              <div class="Bank">{{ $t("storedValuPoints") }}:</div>
              <div class="BankName">{{ addBalance }}</div>
            </div>
          </div>
          <div class="PopupsBtn clearfix">
            <div class="silverBorder btnBorder_all">
              <button
                type="submit"
                @click=" playSound();stored(); gaData(); "
                class="redBtn btn_all"
              >{{ $t("finish") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
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
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    resetaddBalance() {
      this.addBalance = ''
    },
    //儲值確認談窗||

    storedConfirm() {
      var pointMondy = document.getElementById('addBalance').value

      if (this.addBalance == 0 || this.selected == null) {
        this.valueVisible = true
      } else {
        this.visiable = true
        // $('#submitModal').modal('show')
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
      //   ga('send', 'event', {
      //     eventCategory: 'StoredValue',
      //     eventAction: 'Stored_Click',
      //     eventLabel: this.name,
      //     eventValue: this.addBalance
      //   })

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