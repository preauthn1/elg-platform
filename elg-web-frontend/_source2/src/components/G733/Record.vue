<template>
  <div id="G733">
    <LoginHeader></LoginHeader>
    <!-- LoginMain -->
    <div class="LoginMain">
      <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
      <div class="mainAll">
        <div class="pcmainBg">
          <div class="mainLogin"></div>

          <div class="loginInfo">
            <div class="info clearfix">
              <button
                class="yellowInput input_all"
                type="button"
                id="dropdownMenuButton"
                data-toggle="dropdown"
                aria-haspopup="true"
                aria-expanded="false"
              >
                <span class="inputFontLeft">類型</span>
                <span class="inputFontRight">V</span>
              </button>
              <div class="dropdown-menu yellow_Dropdown" aria-labelledby="dropdownMenuButton">
                <div class="gray_dropBg">
                  <a class="dropdown-item" href="#">紅包紀錄（領取紅包）</a>
                </div>
                <div class="gray_dropBg">
                  <a class="dropdown-item" href="#">紅包紀錄（發送紅包）</a>
                </div>
                <div class="gray_dropBg">
                  <a class="dropdown-item" href="#">紅包紀錄（退還紅包）</a>
                </div>
                <div class="gray_dropBg">
                  <a class="dropdown-item" href="#">活動轉入紀錄</a>
                </div>
                <div class="gray_dropBg">
                  <a class="dropdown-item" href="#">平台轉入轉出紀錄</a>
                </div>
                <div class="gray_dropBg">
                  <a class="dropdown-item" href="#">客服轉入轉出紀錄</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
export default {
  components: {
    LoginHeader
  }
}
</script>