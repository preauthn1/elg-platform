<template>
  <!--  -->
  <!-- v-if="getApi_request.isVisible" -->

  <div v-if="getApi_request.isVisible" class="mask">
    <div class="modalBlueAll">
      <div>
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
      <div>
        <!-- 帳號鎖定 9006 -->
        <div
          v-if="getApi_request.code == 9006"
          @click="resHide(9006)"
          class="grayBtn modalBtn"
          style="margin:auto;"
        >
          <button style="background:none;border:none">{{ $t("confirm") }}</button>
        </div>
        <!-- 手機未驗證 9007 -->
        <div
          v-else-if="getApi_request.code == 9007"
          @click="resHide(9007)"
          class="grayBtn modalBtn"
          style="margin:auto;"
        >
          <button style="background:none;border:none">{{ $t("confirm") }}</button>
        </div>
        <!-- 信箱未驗證 9008 -->
        <div
          v-else-if="getApi_request.code == 9008"
          @click="resHide(9008)"
          class="grayBtn modalBtn"
        >
          <button style="background:none; border:none;">{{ $t("confirm") }}</button>
        </div>
        <!-- 註冊成功 1000 -->
        <div
          v-else-if="getApi_request.code == 1000 && getApi_request.type == 'signup'"
          class="signupModalBtn grayBtn modalBtn"
          @click="signupSuccess()"
        >
          <button style="background:none; border:none;">{{ $t("confirm") }}</button>
        </div>
        <!-- gameapp 換密碼成功 1000 -->
        <div
          v-else-if="getApi_request.code == 1000 && getApi_request.type == 'vendorPass'"
          class="signupModalBtn grayBtn modalBtn"
          @click="resHide('vendorPass')"
        >
          <button style="background:none; border:none;">{{ $t("confirm") }}</button>
        </div>
        <!-- 一般錯誤 -->
        <div v-else @click="resHide()" class="btn btn-secondary">
          <button style="background:none; border:none;">{{ $t("confirm") }}</button>
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
    /// 彈窗關
    resHide(code) {
      if (code == '9006') {
        this.$router.push('/RetrievePassword')
      } else if (code == '9007') {
        this.$router.push('/PhoneVerification')
      } else if (code == '9008') {
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