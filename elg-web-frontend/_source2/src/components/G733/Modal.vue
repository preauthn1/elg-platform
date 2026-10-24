<template>
  <!--  -->
  <div id="G733">
    <div class="mask" v-if="getApi_request.isVisible">
      <div class="Popups_Modal">
        <div class="PopupsTitle">{{ $t("prompt") }}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <p v-if="getApi_request.code !== 2002">
              <span>
                {{ getApi_request.msg }}
                <br />
              </span>
            </p>
            <p v-if="getApi_request.code == 2002">
              <span v-for="(item, i) in getApi_request.msg" :key="i">
                {{ item }}
                <br />
              </span>
            </p>
          </div>
          <!-- 帳號鎖定 9006 -->
          <div class="PopupsBtn" v-if="getApi_request.code == 9006" @click="resHide(9006)">
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>

          <!-- 手機未驗證 9007 -->
          <div class="PopupsBtn" v-else-if="getApi_request.code == 9007" @click="resHide(9007)">
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>

          <!-- 信箱未驗證 9008 -->

          <div class="PopupsBtn" v-else-if="getApi_request.code == 9008" @click="resHide(9008)">
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>

          <!-- 註冊成功 1000 -->

          <div
            class="PopupsBtn"
            v-else-if="getApi_request.code == 1000 && getApi_request.type == 'signup'"
            @click="signupSuccess()"
          >
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>
          <!-- gameapp 換密碼成功 1000 -->
          <div
            class="PopupsBtn"
            v-else-if="getApi_request.code == 1000 && getApi_request.type == 'vendorPass'"
            @click="resHide('vendorPass')"
          >
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>

          <!-- 9998 TOKEN ERROR 轉跳 LOGIN -->
          <div class="PopupsBtn" v-else-if="getApi_request.code == 9998" @click="resHide('9998')">
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>
          <!-- 一般錯誤 -->
          <div class="PopupsBtn" v-else @click="resHide()">
            <div class="silverBorder btnBorder_all modalBtn">
              <button type="submit" class="redBtn btn_all">{{ $t("confirm") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import Global from '@/Global'
import { mapGetters, mapMutations } from 'vuex'
import axios from 'axios'

export default {
  name: 'App',
  data() {
    return {
      loadingCount: 0,
      lang: localStorage.getItem('LOCALE')
    }
  },
  computed: {
    ...mapGetters(['getApi_request'])
  },
  mounted() {
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
    /// 彈窗關
    resHide(code) {
      if (code == '9006') {
        this.$router.push('/RetrievePassword')
      } else if (code == '9007') {
        this.$router.push('/PhoneVerification')
      } else if (code == '9008') {
      } else if (code == '9998') {
        // sessionStorage.removeItem('token')
        window.sessionStorage.clear()
        localStorage.removeItem('user_account')
        window.location.assign('/login')
      }
      this.$store.commit('HIDE_API_RES')
    },
    /// 註冊成功
    signupSuccess() {
      window.location = '/login'
    }
  },
  created() {}
}
</script>