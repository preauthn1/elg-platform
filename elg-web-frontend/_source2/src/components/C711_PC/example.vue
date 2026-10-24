<template>
  <div class="Lobby">
    <div class="bgi">
      <LobbyHeader></LobbyHeader>
      <div class="point">123</div>
    </div>
    <PopUp></PopUp>
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
      //   reader.onload = function() {
      //     vm.avatar = this.result
      //   }
      reader.onload = function(e) {
        vm.dealImage(this.result, { width: 1920 }, function(base) {
          vm.avatar = base
          vm.$store.dispatch('actionAvatar', vm.avatar)
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