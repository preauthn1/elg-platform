<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <form>
      <div class="ChangeImgItem">
        <div class="ChangeImgSelect">
          <!-- <select>
                <option><input type="file" accept="image/*" capture="camera" placeholder="$t('camera')"></option>
                <option><input type="file" accept="image/*" capture="camera">{{ $t("chooseFromAlbums") }}</option>
          </select>-->
          <b-dropdown size="sm" variant="link" toggle-class="text-decoration-none" no-caret>
            <template slot="button-content">
              <img src="../../assets/img/v_world/extended_dots.png" />
            </template>
            <b-dropdown-item-button>
              <label>
                <div class="dropdownFont">{{ $t('camera') }}</div>
                <!-- <span></span> -->
                <input type="file" capture="camera" style="display:none" />
              </label>
            </b-dropdown-item-button>
            <b-dropdown-item-button>
              <label>
                <div class="dropdownFont">{{ $t("chooseFromAlbums") }}</div>
                <input @change="uploadImg" type="file" accept="image/*" style="display:none" />
              </label>
            </b-dropdown-item-button>
          </b-dropdown>
        </div>
        <div class="ImgSelect">
          <img :src="avatar" />
        </div>
        <div @click="playSound();update()" class="imgfontAll">
          <span>{{ $t("finish") }}</span>
        </div>
      </div>
    </form>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>
<script>
import LoginHeader from '@/components/v_world/Login_header'
import maskModal from './Modal'
export default {
  components: {
    LoginHeader,
    maskModal
  },
  data() {
    return {
      avatar:
        sessionStorage.getItem('avatar') ||
        require('../../assets/img/v_world/user.jpg')
    }
  },
  mounted() {
    //手機方向
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
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
      reader.onload = function(e) {
        vm.dealImage(this.result, { width: 1920 }, function(base) {
          vm.avatar = base
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
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
label {
  margin: 0;
}
</style>
