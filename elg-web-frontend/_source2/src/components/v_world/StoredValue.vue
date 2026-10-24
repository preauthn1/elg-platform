<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>

    <div class="AllContent">
      <div class="pointValue">
        {{ $t("points") }}:
        <p>{{ vPoints }}</p>
      </div>
      <div class="bankSelect">
        <div class="bankRightBtn">
          <b-form-select v-model="selected" class="bankSelectBg">
            <option :value="null">{{ $t('pleaseSelectABank') }}</option>
            <option
              v-for="(item,i) in getOrderTypes"
              :key="i"
              :value="item"
              class="optionFont"
            >{{ item.name }}</option>
          </b-form-select>
          <img src="../../assets/img/v_world/triangle_right.png" class="RightBtn" />
        </div>

        <div class="inputBg textbox">
          <input
            id="addBalance"
            v-model="addBalance"
            pattern="[0-9]*"
            type="number"
            :placeholder="$t('pleaseEnterTheNumberOfPoints')"
          />
          <img src="../../assets/img/v_world/edit_text_clear_btn.png" @click="resetaddBalance()" />
        </div>
      </div>
      <div class="ValueBtn">
        <div class="imgfontAll" @click="storedConfirm();">
          <span>{{ $t("submit") }}</span>
        </div>
        <!-- 未輸入彈窗 -->
        <div v-if="valueVisible" class="mask">
          <div class="modalBlueAll">
            <p v-if="this.selected == null">{{$t("pleaseSelectABank")}}</p>
            <p v-if="this.addBalance == 0">{{$t("pleaseEnterTheNumberOfPoints")}}</p>
            <div
              @click="errorClose()"
              class="btn btn-secondary"
              style="background:none;border:none"
            >
              <div class="grayBtn modalBtn">{{ $t("confirm") }}</div>
            </div>
          </div>
        </div>

        <div v-if="visiable" class="StoredModal">
          <div class="StoredModalCon">
            <div class="StoredTitile">
              <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
              <div class="TitleFontClose clearfix">
                <div class="float-left TitleFont">{{ $t("prompt") }}</div>
                <div class="float-right TitleClose">
                  <img @click="close()" src="../../assets/img/v_world/closeBtn.png" class="close" />
                </div>
              </div>
            </div>
            <div class="StoredContent">
              <div class="ContentTitle">{{ $t("confirmDepositInformation") }}</div>
              <div class="ContentBgFont ContentFontStored">
                <span>{{ $t("bankName") }}:</span>
                <p>{{ name }}</p>
                <p>
                  <!-- <blog-post v-for="(item,i) in selected" :title="item.name" :key="i"></blog-post> -->
                </p>
                <span>{{ $t("storedValuPoints") }}:</span>
                <p>{{ addBalance }}</p>
              </div>
              <div class="StoredModalBtn">
                <div class="Gimgfont greenBtn" @click="playSound();stored();gaData()">
                  <span>{{ $t("confirm") }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>

<script>
import LoginHeader from '@/components/v_world/Login_header'
import maskModal from './Modal'

import { mapGetters, mapActions } from 'vuex'
import { constants } from 'zlib'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getOrderTypes'])
  },
  data() {
    return {
      visiable: false,
      valueVisible: false,
      vPoints: window.sessionStorage.getItem('balance'),
      selected: null,
      addBalance: '',
      name: '',
      ordertypeId: ''
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
      //   console.log(pointMondy)
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
      //   ga('send', 'event', {
      //     eventCategory: 'StoredValue',
      //     eventAction: 'StoredValue_Click',
      //     eventLabel: this.name,
      //     eventValue: this.addBalance
      //   })
    },
    gaData() {
      //   console.log(this.ordertypeId, this.name, this.addBalance)
      ga('require', 'ecommerce', 'ecommerce.js')
      //   收集結帳商品資料用
      ga('ecommerce:addItem', {
        id: this.ordertypeId, // 商品ID，必填
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
    }
  },

  created() {
    this.$store.dispatch('actionOrderTypes')
    this.$store.dispatch('actionMember')
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
