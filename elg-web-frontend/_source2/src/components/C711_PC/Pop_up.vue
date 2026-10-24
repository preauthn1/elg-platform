<template>
  <div class="popUp_block" v-if="getApi_request.isVisible ">
    <div class="wrap">
      <div class="title">{{ $t("prompt") }}</div>
      <div class="content">
        <p v-if="getApi_request.code != 2002 && getApi_request.code != 99961">
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
        <p v-if="getApi_request.code == 99961">{{$t('systemMaintenanceUpgrade')}}</p>
      </div>
      <div class="footer">
        <!-- 帳號鎖定 9006 -->
        <div v-if="getApi_request.code == 9006" @click="resHide(9006)">
          <div class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
        <!-- 手機未驗證 9007 -->
        <div v-else-if="getApi_request.code == 9007" @click="resHide(9007)">
          <div class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
        <!-- 信箱未驗證 9008 -->
        <div v-else-if="getApi_request.code == 9008" @click="resHide(9008)">
          <div class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
        <!-- 註冊成功 1000 -->
        <div
          v-else-if="getApi_request.code == 1000 && getApi_request.type == 'signup'"
          @click="signupSuccess()"
        >
          <div class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
        <!-- gameapp 換密碼成功 1000 -->
        <div
          v-else-if="getApi_request.code == 1000 && getApi_request.type == 'vendorPass'"
          @click="resHide('vendorPass')"
        >
          <div class="BtnShare blueBtnShare">{{ $t("confirm") }}</div>
        </div>
        <!-- 9998 TOKEN ERROR 轉跳 LOGIN -->
        <div v-else-if="getApi_request.code == 9998" @click="resHide(9998)">
          <div class="btn-blue">
            <button>{{ $t("confirm") }}</button>
          </div>
        </div>
        <!-- 一般錯誤 -->
        <div v-else @click="resHide()">
          <div class="btn-blue">
            <button>{{ $t("confirm") }}</button>
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
      lang: localStorage.getItem('LOCALE'),
      token: [REDACTED]'token'),
      eventIframePop: sessionStorage.getItem('eventIframePop')
    }
  },
  computed: {
    ...mapGetters(['getApi_request'])
  },
  mounted() {},
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