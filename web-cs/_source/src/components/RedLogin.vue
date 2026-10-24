<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="allContent whiteAll">
      <div class="whiteCon">
        <div class="redLoginTop">
          <div class="rTopFont">{{$t('redEnvelope')}}</div>
          <div class="rTopFont">{{redEnvelData.amount}}</div>
        </div>
        <div class="inputCon clearfix">
          {{$t('password')}}：
          <input
            class
            id="redPassword"
            :placeholder="$t('enterUserPassword')"
            type="password"
            v-model="redPw"
          />
          <div class="closeInputBtn float-right">
            <img src="../assets/img/close.png" @click="repassword()" />
          </div>
        </div>
        <!-- <a href="RedReceive"> -->
        <!-- <div style="width:100%; margin:auto;  text-align:center; ">
          <button
            class="RedLoginBtn blueBtnPic buttonAll ContentFont"
            style="margin:auto; text-align:center; border:none;outline:none;"
            @click="redSendComplete()"
            id="completeBtn"
          >{{$t('complete')}}</button>
        </div>-->
        <!-- </a> -->
        <!-- c711 -->
        <div class="sendBtn blueBtnShare" style="width:40%;">
          <button
            type="submit"
            @click="redSendComplete()"
            style="background:none; border:none;"
          >{{$t('complete')}}</button>
        </div>
      </div>
    </div>
  </div>
</template>
<script type="text/javascript" src="http://example.invalid/jquery.blockUI.js"></script>
<script>
import listTitle from '@/components/ListTitle'
import axios from 'axios'
export default {
  components: {
    listTitle
  },
  data() {
    return {
      redPw: '',
      redEnvelData: '',
      postInfos: [],
      msg: 'Loading...',
      spinnerStatus: false
    }
  },
  mounted() {
    // $.unblockUI()
    let redEnvelId = this.$route.params.redEnvelId
    var data = {
      red_envelope_id: redEnvelId
    }
    var url = 'api/redEnvelope/view'
    var post = {
      info: data,
      url: url
    }
    this.$store.dispatch('post', post).then(res => {
      if (res.data.code == 1000) {
        this.redEnvelData = res.data.data
      } else {
        alert(res.data.message)
      }
    })
  },

  methods: {
    redSendComplete() {
      if (this.redPw == '') {
        alert(this.$t('enterUserPassword'))
      } else {
        let redEnvelId = this.$route.params.redEnvelId
        var data = {
          red_envelope_id: redEnvelId,
          password: [REDACTED]
        }
        var url = 'api/redEnvelope/validatePassword'
        var post = {
          info: data,
          url: url
        }
        this.$store.dispatch('post', post).then(res => {
          if (res.data.code == 1000) {
            //   $('#completeBtn').css('color', 'gray')
            //   $('#completeBtn').attr('disabled', 'false')

            window.location = '/RedSend'
          } else {
            alert(res.data.message)
            //   $('#completeBtn').attr('disabled', 'false')
            window.location.reload()
          }
        })
      }
    },
    // completUI() {
    //   $.blockUI({
    //     message: '<h1>讀取中...</h1>'
    //   })
    //   //3秒後，解除BlockUI
    //   setTimeout(function() {
    //     $.unblockUI()
    //   }, 3000)
    // },
    repassword() {
      this.redPw = ''
    }
  }
}
</script>
<style scoped>
</style>

