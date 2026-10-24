<template>
  <div id="G733">
    <div class="mobileHeader">
      <LoginHeader></LoginHeader>
    </div>

    <div class="single_block">
      <div class="LoginMain" style="height:90vh;">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainAll">
          <div class="pcmainBg">
            <div class="mainLogin"></div>
            <div class="loginInfo">
              <!-- 大頭照 -->
              <div class="personalPic">
                <div class="circlePic">
                  <!-- <img :src="cs" v-if="friend.type == 0" /> -->
                  <img :src="avatar== null||avatar== '' ?DefaultAvatar:avatar " />
                </div>
                <!-- 手機板上傳 -->
                <div class="camera mobileCamera">
                  <img src="../../assets/img/G733/camera.png" v-on:click="show = !show" />
                </div>
                <!-- PC版上傳 -->
                <label class="camera pcCamera">
                  <input @change="uploadImg" type="file" accept="image/*" style="display:none" />
                  <img src="../../assets/img/G733/camera.png" />
                </label>
              </div>
              <!-- 暱稱 -->
              <div class="goldBorder btnBorder_all">
                <a
                  href="/SettingName"
                  onclick="ga('send', 'event', 'SettingPersonal', 'SettingName_Click', 'SettingName');"
                >
                  <div class="blackBtn settingBtn">
                    <div class="settingPfont">{{ $t("nickname") }}</div>&nbsp;
                    <span class="settingPdata">{{Nickname!=='undefined'?Nickname:''}}</span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
              <!-- 帳號 -->
              <div class="info">
                <div type="text" class="grayInput input_all" disabled="disabled">
                  <div class="SettingInputLeft">{{ $t("account") }}</div>&nbsp;
                  <div class="SettingInputRight">{{Username}}</div>
                </div>
              </div>
              <!-- 手機號碼 -->
              <div class="info">
                <div type="text" class="grayInput input_all" disabled="disabled">
                  <div class="SettingInputLeft">{{ $t("cellphoneNumber") }}</div>&nbsp;
                  <div
                    class="SettingInputRight"
                  >{{Mobile =='undefined' || Mobile ==null ?'':Mobile}}</div>
                </div>
              </div>
              <div class="goldBorder btnBorder_all">
                <a
                  href="/ChangePassword"
                  onclick="ga('send', 'event', 'SettingPersonal', 'ChangePassword_Click', 'ChangePassword');"
                >
                  <div class="blackBtn settingBtn">
                    <div class="settingPfont">{{ $t("changePassword") }}</div>&nbsp;
                    <span class="settingPdata"></span>
                    <img src="../../assets/img/G733/R.png" alt class="RightBtnPic" />
                  </div>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
    <div class="transition">
      <transition name="fade">
        <div v-if="show">
          <div class="fadeMenu">
            <div class="fadeFont fadeBottomBorder">
              <label>
                {{ $t('camera') }}
                <input type="file" capture="camera" style="display:none" />
              </label>
            </div>
            <div class="fadeFont">
              <label>
                {{ $t("chooseFromAlbums") }}
                <input
                  @change="uploadImg"
                  type="file"
                  accept="image/*"
                  style="display:none"
                />
              </label>
            </div>
          </div>
          <div class="fadeMenu" v-on:click="show = !show">
            <div class="fadeFont">{{ $t('cancel1') }}</div>
          </div>
        </div>
      </transition>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  data() {
    return {
      vPoints: window.sessionStorage.getItem('balance'),
      Avatar: window.sessionStorage.getItem('avatar'),
      Email: window.sessionStorage.getItem('email'),
      Mobile: window.sessionStorage.getItem('mobile'),
      Nickname: window.sessionStorage.getItem('nickname'),
      Username: window.sessionStorage.getItem('username'),
      DefaultAvatar: require('../../assets/img/C711_Mobile/user.jpg'),
      avatar: sessionStorage.getItem('avatar'),
      show: false
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
    uploadImg(e) {
      let file = e.target.files[0]
      if (!/image\/\w/.test(file.type)) {
        alert('請確保檔案為影象型別')
        return false
      }
      let reader = new FileReader()
      let vm = this
      reader.readAsDataURL(file)
      //   reader.onload = function() {
      //     vm.avatar = this.result
      //   }
      reader.onload = function(e) {
        vm.dealImage(this.result, { width: 1920 }, function(base) {
          vm.avatar = base
          vm.$store.dispatch('actionAvatar', vm.avatar)
          $('#headerSmallPic').attr('src', vm.avatar)
        })
      }
    },

    dealImage(path, obj, callback) {
      var img = new Image()
      img.src = path
      img.onload = function() {
        var that = this
        // 預設按比例壓縮
        var w = that.width,
          h = that.height,
          scale = w / h
        w = obj.width || w
        h = obj.height || w / scale
        var quality = 0.7 // 預設圖片質量為0.7
        //生成canvas
        var canvas = document.createElement('canvas')
        var ctx = canvas.getContext('2d')
        // 建立屬性節點
        var anw = document.createAttribute('width')
        anw.nodeValue = w
        var anh = document.createAttribute('height')
        anh.nodeValue = h
        canvas.setAttributeNode(anw)
        canvas.setAttributeNode(anh)
        ctx.drawImage(that, 0, 0, w, h)
        // 影象質量
        if (obj.quality && obj.quality <= 1 && obj.quality > 0) {
          quality = obj.quality
        }
        // quality值越小，所繪製出的影象越模糊
        var base64 = canvas.toDataURL('image/jpeg', quality)
        // 回撥函式返回base64的值
        callback(base64)
      }
    },
    update() {
      this.$store.dispatch('actionAvatar', this.avatar)
    }
  }
}
</script>
<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: all 0.4s ease;
}

.fade-enter {
  transform: translateY(50px);
  opacity: 0;
}

.fade-leave-to {
  transform: translateY(50px);
  opacity: 0;
}
</style>