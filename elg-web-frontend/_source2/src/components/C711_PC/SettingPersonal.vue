<template>
  <div class="single_block">
    <div>
      <LobbyHeader></LobbyHeader>
      <div class="personal_wrap">
        <div class="personal_content">
          <!-- 頭像 -->
          <div class="profile_block">
            <div class="personalPic">
              <div class="circlePic">
                <img :src="avatar =='null'?DefaultAvatar:avatar?avatar:DefaultAvatar" />
              </div>
              <div class="camera">
                <label>
                  <input @change="uploadImg" type="file" accept="image/*" style="display:none" />
                  <img src="../../../static/img/C711_PC/Group4994.png" />
                </label>
              </div>
            </div>
          </div>

          <!-- 基本資料 -->
          <div class="info_block fontsizelogin">
            <div class="ele-border input-X" style="margin-bottom: 30px;">
              <div>
                <span class="inputFontLeft">{{$t('nickname')}}</span>
                <p
                  class="inputFontRight ele_border_in gradual-black"
                  style="right: 60px;"
                >{{Nickname}}</p>
              </div>
              <router-link class="penBtn" to="/SettingName">
                <img src="../../../static/img/C711_PC/pen.png" />
              </router-link>
            </div>

            <div class="ele-border input-X" style="margin-bottom: 30px; ">
              <div class style=" ">
                <span class="inputFontLeft">{{$t('account')}}</span>
                <p class="inputFontRight ele_border_in dark-brown" style="right: 60px;">{{Username}}</p>
              </div>
            </div>

            <div class="ele-border input-X" style="margin-bottom: 30px; ">
              <div class style=" ">
                <span class="inputFontLeft">{{$t('cellphoneNumber')}}</span>
                <p
                  class="inputFontRight ele_border_in dark-brown"
                  style="right: 60px;"
                >{{Mobile =='undefined' || Mobile ==null?'':Mobile}}</p>
              </div>
            </div>

            <div class="ele-border input-X" style="margin-bottom: 30px;">
              <div>
                <span class="inputFontLeft">{{$t('AccountPassword')}}</span>
                <p class="inputFontRight ele_border_in gradual-black" style="right: 60px;"></p>
              </div>
              <router-link class="penBtn" to="/ChangePassword">
                <img src="../../../static/img/C711_PC/pen.png" />
              </router-link>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- <PopUp></PopUp> -->
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import LobbyHeader from '@/components/C711_PC/Lobby_header'
import PopUp from './Pop_up'
export default {
  components: {
    LobbyHeader,
    PopUp
  },
  data() {
    return {
      vPoints: window.sessionStorage.getItem('balance'),
      Avatar: window.sessionStorage.getItem('avatar'),
      Email: window.sessionStorage.getItem('email'),
      Mobile: window.sessionStorage.getItem('mobile'),
      Nickname: window.sessionStorage.getItem('nickname'),
      Username: window.sessionStorage.getItem('username'),
      DefaultAvatar: require('../../../static/img/C711_PC/lion.jpg'),
      avatar: sessionStorage.getItem('avatar'),
      show: false
    }
  },
  methods: {
    uploadImg(e) {
      let file = e.target.files[0]
      if (!/image\/\w/.test(file.type)) {
        alert('請確保檔案為影象型別')
        return false
      }
      let reader = new FileReader()

      let vm = this
      reader.readAsDataURL(file)

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