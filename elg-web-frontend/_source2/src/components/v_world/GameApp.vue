<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <div>
        <h4>{{$route.query.vendor}}</h4>
      </div>
      <div
        v-if="getVendor_Info.ios_open==''"
        class="GameAppAcc info_text_box GameAppInput clearfix"
      >
        {{ $t("loginAccount") }} :
        <span>{{ getVendor_Info.username }}</span>
        <div
          :data-clipboard-text="getVendor_Info.username"
          @click="copy('acct')"
          class="CtrlCBtn float-right coby"
        >
          <img src="../../assets/img/v_world/CtrlC.png" />
        </div>
      </div>
      <div v-if="getVendor_Info.ios_open==''" class="GameAppPw info_text_box GameAppInput clearfix">
        {{ $t("loginPassword") }} :
        <span>{{ getVendor_Info.password }}</span>
        <div
          :data-clipboard-text="getVendor_Info.password"
          @click="copy('pass')"
          class="CtrlCBtn float-right coby"
        >
          <img src="../../assets/img/v_world/CtrlC.png" />
        </div>
      </div>
      <div class="GameAppOpen">
        <div class="OpenFont" v-if="getVendor_Info.ios_open==''">{{ $t("downLoadAPP") }}</div>
        <div class="OpenFont" v-else>{{ $t("openTheGame") }}</div>
        <div class="OpenBtn clearfix">
          <div
            @click="Android()"
            class="AndroidBtn androIosBtn float-left"
            id="AndroidBtn"
            onClick="ga('send', 'event', 'GameApp', 'Android_Click', 'Android');"
          ></div>
          <div
            @click="IOS()"
            class="IosBtn androIosBtn float-left"
            id="IosBtn"
            onClick="ga('send', 'event', 'GameApp', 'IOS_Click', 'IOS');"
          ></div>
        </div>
        <div v-if="getVendor_Info.ios_open==''" class="gameIn">
          <div class="float-left gameInLeft">{{ $t("pleaseEnterTheGame") }}</div>

          <div class="float-left gameInRight">
            <img v-if="vendorId==3" src="../../assets/img/v_world/icon.png" />
            <img v-if="vendorId ==5" src="../../assets/img/v_world/icon1.png" />
          </div>
        </div>
      </div>
      <div class="GameAppFont" style="font-size:15px;">
        <div v-if="getVendor_Info.ios_open==''" class="iosOpenFont">
          <p>{{ $t("youCanChangeYourLoginPassword") }}</p>
          <div class="GameAppPwSet">
            <p>{{ $t("yourNewPasswordMustContain") }}</p>
            <ul>
              <li>{{ $t("atLeast6characters") }}</li>
              <li>{{ $t("uppercaseAndLowercaseLetters") }}</li>
              <li>{{ $t("atLeastOneNumber") }}</li>
            </ul>
          </div>
          <div class="ifnewpw">*{{ $t("ifYouHaveModifiedIt") }}*</div>
        </div>
        <div v-else style="font-size:15px;">
          <p>{{ $t("afterDownloading") }}</p>
          <p>{{ $t("pleaseReturnTo") }}</p>
        </div>
      </div>
      <div v-if="getVendor_Info.ios_open==''" class="GameAppNewPw clearfix">
        <div class="NewPwFont">{{ $t("setnewpassword") }}:</div>
        <div class="NewPwInput">
          <span class="newpwfont">Ex:Aa12345</span>
          <input
            @keyup="changeDisable()"
            v-model="new_password"
            name="newPass"
            v-validate="{min:6, regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)[a-zA-Z\d]{6,}$/}"
            :data-vv-as="$t('newPassword')"
            maxlength="14"
            type="password"
          />
          <label
            v-show="errors.has('newPass')"
            for="newPass"
            class="validate"
          >{{ errors.first('newPass') }}</label>
        </div>
      </div>
      <div
        v-if="getVendor_Info.ios_open==''"
        class="GameAppBtn imgfontAll"
        style="margin-top:20px;"
      >
        <button
          @click="playSound();resetVendorPass()"
          :disabled="!isDisabled"
          style="border: none; background: none;"
        >
          <span>{{ $t("changePassword") }}</span>
        </button>
      </div>
    </div>
    <!-- 複製 -->
    <div v-if="isAcctCopy || isPassCopy" class="mask">
      <div class="modalBlueAll">
        <p v-if="isAcctCopy">{{ $t("loginAccount") }} {{ $t("copied") }}</p>
        <p v-if="isPassCopy">{{ $t("loginPassword") }} {{ $t("copied") }}</p>
      </div>
    </div>
    <!-- 餘額不足 轉點 -->
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
          >{{ template }}{{ $t("amount") }} : {{ vWorld_balance }}</div>
          <div class="nomoneyFont">{{ $t("gamepublisher") }} : {{vendorName}}</div>
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
    <!-- open app -->
    <!-- <iframe id="open_app" style="display:none" frameborder="0"></iframe> -->

    <!-- 下載連結窗iframe-->
    <!-- <div v-show="AppDownload" class="downloadFrame">
        <div class="frame_close" @click="AppDownload = false">
            <img src="../../assets/img/v_world/closeBtn.png" />
        </div>
        <div class="frame_wrap">
            <h4>Downlowd App</h4>
            <iframe id="download_frame" frameborder="0"></iframe>
        </div>
    </div>-->

    <!-- 下載連結a -->
    <div v-show="AppDownload" class="mask">
      <div class="modalBlueAll modalContent">
        <p style="margin:0px auto 20px;">Download App</p>
        <div class="comfirmBtnAll clearfix">
          <div class="float-left greenBtn modalBtn">
            <a
              id="downloadLink"
              target="_blank"
              style="color:#fff"
              @click="AppDownload = false"
            >{{$t('downLoad')}}</a>
          </div>
          <div class="float-right redBtn modalBtn" @click="AppDownload = false">
            <span>{{$t('cancel1')}}</span>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LoginHeader from '@/components/v_world/Login_header'
import maskModal from './Modal'
// 複製外掛 clipboard
import clipboard from 'clipboard'
import { setTimeout } from 'timers'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['getVendor_Info'])
  },
  data() {
    return {
      new_password: '',
      isDisabled: false,
      isAcctCopy: false,
      isPassCopy: false,
      //deposit data
      template: sessionStorage.getItem('Template'),
      vendorName: sessionStorage.getItem('vendor_name'),
      vWorld_balance: sessionStorage.getItem('balance'),
      vendorId: sessionStorage.getItem('vendor_id'),
      game_id: '',
      depositVal: 0,
      depositVisible: false,
      // AppDownload
      AppDownload: false
    }
  },
  mounted() {
    // console.log(this.vendorId)
    // $('#IosBtn').css('display', 'none')
    // $('#AndroidBtn').css('display', 'none')
    // //判斷IOS ANDROID
    // $(function() {
    //   var u = navigator.userAgent,
    //     app = navigator.appVersion
    //   var isAndroid = u.indexOf('Android') > -1 || u.indexOf('Linux') > -1 //g
    //   var isIOS = !!u.match(/\(i[^;]+;( U;)? CPU.+Mac OS X/)
    //   if (isAndroid) {
    //     console.log('安卓')
    //     $('#AndroidBtn').css('display', 'block')
    //     //   $('.IosBtn').attr('disabled', 'true')
    //   }
    //   if (isIOS) {
    //     console.log('蘋果')
    //     $('#IosBtn').css('display', 'block')
    //     //   $('.AndroidBtn').attr('disabled', 'true')
    //   }
    // })
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
    GOApp(scheme_url, download_url, timeout = 3000) {
      let startTime = Date.now()
      if (scheme_url !== '') {
        window.location = scheme_url
      }
      // $('#open_app').attr('src',scheme_url)
      let vm = this
      let t = setTimeout(() => {
        let endTime = Date.now()
        if (!startTime || endTime - startTime < timeout + 100) {
          vm.AppDownload = true
          // $('#download_frame').attr('src',download_url)
          $('#downloadLink').attr('href', download_url)
        }
      }, timeout)
      window.onblur = function() {
        clearTimeout(t)
      }
    },
    Android() {
      this.$store.dispatch('actionOne_balance', this.vendorId).then(balance => {
        let vendor_balance = parseInt(balance.toString().replace(',', ''), 10)
        if (vendor_balance < 0) {
          this.game_id = 'Android'
          this.depositVisible = true
        } else {
          this.GOApp(
            this.getVendor_Info.android_open,
            this.getVendor_Info.android_download
          )
          //   window.open(this.getVendor_Info.android_download)
        }
      })
    },
    IOS() {
      this.$store.dispatch('actionOne_balance', this.vendorId).then(balance => {
        let vendor_balance = parseInt(balance.toString().replace(',', ''), 10)
        if (vendor_balance < 0) {
          this.game_id = 'IOS'
          this.depositVisible = true
        } else {
          this.GOApp(
            this.getVendor_Info.ios_open,
            this.getVendor_Info.ios_download
          )
          //   window.open(this.getVendor_Info.ios_download)
        }
      })
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
    // 遊戲餘額不足，點數轉入
    deposit() {
      let data = {}
      data['isDeposit'] = true
      data['vendor_id'] = this.vendorId
      data['point'] = this.depositVal
      data['game_id'] = this.game_id
      this.$store.dispatch('balanceToVendor', data)
      this.depositVisible = false
    },
    changeDisable() {
      if (this.new_password !== '') {
        this.$validator.validate('newPass').then(valid => {
          if (valid) {
            this.isDisabled = true
          } else {
            this.isDisabled = false
          }
        })
      }
    },
    resetVendorPass() {
      let newPass = this.new_password
      this.$store.dispatch('action_resetVendorPass', newPass)
      this.new_password = ''
    },
    copy(e) {
      new clipboard('.coby')
      if (e == 'acct') {
        this.isAcctCopy = true
      } else {
        this.isPassCopy = true
      }
      setTimeout(() => {
        this.isAcctCopy = false
        this.isPassCopy = false
      }, 1500)
    },
    // 取遊戲商餘額
    // getVendorBalance() {
    //   let vendor_id = sessionStorage.getItem('vendor_id')
    //   this.$store.dispatch('actionOne_balance', vendor_id)
    // },
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    }
  },
  created() {
    this.$store.dispatch('action_vendorInfo')
    this.depositVal = Math.round(
      parseInt(this.vWorld_balance.toString().replace(',', ''), 10) / 2
    )
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
.AndroidBtn a,
.IosBtn a {
  display: block;
  width: 100%;
  height: 100%;
}
.downloadFrame {
  width: calc(100% - 20px);
  height: calc(100vh - 20px);
  margin: 10px;
  position: fixed;
  z-index: 1100;
  top: 0;
  left: 0;
  background-color: #04020c;
  border: 1px solid #aaa;
}
.frame_close {
  width: 30px;
  height: 30px;
  position: absolute;
  right: 5px;
  top: 5px;
}
.frame_close img {
  width: 100%;
  vertical-align: top;
}
.frame_wrap {
  width: 100%;
  height: 100%;
}
.frame_wrap h4 {
  height: 40px;
  line-height: 40px;
  margin: 0;
}
.frame_wrap iframe {
  width: 100%;
  height: calc(100% - 40px);
}
</style>
