<template>
  <div id="G733">
    <LoginHeader></LoginHeader>
    <div class="LoginMain">
      <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
      <div class="mainAll">
        <div class="pageCon">
          <div id="statement">
            <div class="company">{{$t('companyWillVoid')}}</div>
            <div class style="width:90%; text-align:center;margin:auto;">
              <div class="silverBorder btnBorder_all" style=" width:120px; " id="agree">
                <button @click="agree(1)" class="yellowBtn btn_all">{{$t('agree')}}</button>
              </div>
            </div>
          </div>

          <div
            class="silverBorder btnBorder_all"
            id="buttonClick"
            style="width:80%; margin-top:50px;"
          >
            <a :href="iframeSrc" target="_blank">
              <div class="redBtn btn_all">{{$t('clickHerToEnter')}}</div>
            </a>
          </div>
        </div>
        <!-- 彈窗 -->
        <maskModal></maskModal>
        <!-- 豪龍賠率表彈窗 -->
        <div class="mask" v-if="get_isVendorID4">
          <div class="vendorID4" style="width:100%;">
            <div class="closeBtn" @click="ID4Hide()">
              <img src="../../assets/img/C711_Mobile/modalClose.png" />
            </div>
            <img :src="lang=='zh-cn'||lang=='zh-tw'?ID4PicCH:ID4PicEN" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
import { mapGetters, mapActions } from 'vuex'
import axios from 'axios'
import secret from '@/utils/secret'
import md5 from 'js-md5'
import Global from '@/Global'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  data() {
    return {
      iframeSrc: '',
      ID4PicEN: require('../../../static/img/C711_Mobile/s1.jpg'),
      ID4PicCH: require('../../../static/img/C711_Mobile/s2.jpg'),
      lang: localStorage.getItem('LOCALE'),
      agreeType: '',
      gameID: this.$route.params.id
    }
  },
  computed: {
    ...mapGetters(['get_isVendorID4'])
  },
  mounted() {
    //   SBO聲明同意按鈕
    if (this.gameID == 18) {
      $('#buttonClick').css('display', 'none')
      $('#statement').css('display', 'block')
    } else {
      $('#buttonClick').css('display', 'block')
      $('#statement').css('display', 'none')
    }
    // 手機橫向遮罩
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'none')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
    let md5_key = Global.$API_MD5_KEY
    let vendor_id = this.gameID
    let jsonMsg = JSON.stringify({
      vendor_id: vendor_id
    })
    let params = secret.Encrypt(jsonMsg)
    let key = md5(params + md5_key)
    axios
      .post(
        Global.$API_URL + 'api/vendorInfo',
        {
          params: params,
          key: key
        },
        {
          headers: {
            'Accept-Language': localStorage.getItem('LOCALE'),
            Authorization: 'Bearer ' + sessionStorage.getItem('token')
          }
        }
      )
      .then(res => {
        this.iframeSrc = res.data.data.url
        //    豪龍賠率表彈窗
        if (vendor_id == 4) {
          this.$store.commit('SHOW_VENDORID4_OPEN')
        }
      })
      .catch(error => {
        console.log('error', error)
      })
  },
  methods: {
    ID4Hide() {
      // 關閉賠率表
      this.$store.commit('HIDE_VENDORID4')
    },
    agree() {
      // SBO聲明同意按鈕
      $('#buttonClick').css('display', 'block')
      $('#agree').css('display', 'none')

      //
    }
  }
}
</script>

