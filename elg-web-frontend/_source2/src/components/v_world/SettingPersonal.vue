<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <div class="SettingPersonalPic clearfix">
        <div class="float-left">
          <img
            class="img-rounded img-responsive center-block"
            :src="Avatar==='null'?DefaultAvatar:Avatar"
          />
        </div>
        <div
          class="float-right"
          onClick="ga('send', 'event', 'SettingPersonal', 'ChangeImg_Click', 'ChangeImg');"
        >
          <a href="/ChangeImg">
            <img src="../../assets/img/v_world/right.png" />
          </a>
        </div>
      </div>
      <div class="SettingPersonalData">
        <div class="PersonalAll">
          <div class="PersonalItem clearfix">
            <div class="float-left PersonalLeft">{{ $t("nickname") }}</div>
            <div class="float-left PersonalCenter">{{Nickname!=='undefined'?Nickname:''}}</div>
            <div class="float-right PersonalRight">
              <a
                href="/SettingName"
                onClick="ga('send', 'event', 'SettingPersonal', 'SettingName_Click', 'SettingName');"
              >
                <img src="../../assets/img/v_world/right.png" />
              </a>
            </div>
          </div>
          <div class="PersonalItem clearfix">
            <div class="float-left PersonalLeft">{{ $t("account") }}</div>
            <div class="float-left PersonalCenter">{{Username}}</div>
            <div class="float-right PersonalRight"></div>
          </div>
          <div class="PersonalItem clearfix">
            <div class="float-left PersonalLeft">{{ $t("cellphoneNumber") }}</div>
            <div class="float-left PersonalCenter">{{Mobile!=='undefined'?Mobile:''}}</div>
            <div class="float-right PersonalRight"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="blueBtnLong SettingPersonalBtn">
      <div class="blueBtn_Setting clearfix">
        <a
          href="/ChangePassword"
          onClick="ga('send', 'event', 'SettingPersonal', 'ChangePassword_Click', 'ChangePassword');"
        >
          <div class="float-left blueBtnFont">{{ $t("changePassword") }}</div>
          <div class="float-right blueBtnRight">
            <img src="../../assets/img/v_world/right.png" />
          </div>
        </a>
      </div>
    </div>
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
      vPoints: window.sessionStorage.getItem('balance'),
      Avatar: window.sessionStorage.getItem('avatar'),
      Email: window.sessionStorage.getItem('email'),
      Mobile: window.sessionStorage.getItem('mobile'),
      Nickname: window.sessionStorage.getItem('nickname'),
      Username: window.sessionStorage.getItem('username'),
      DefaultAvatar: require('../../assets/img/v_world/user.jpg')
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
  created() {}
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
