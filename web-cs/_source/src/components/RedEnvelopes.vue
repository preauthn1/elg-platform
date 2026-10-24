<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="allContent whiteAll">
      <div class="whiteCon">
        <!-- <div class="point">點數:887,802.37</div> -->
        <div class="inputCon clearfix">
          {{$t('points')}}：
          <input
            class
            :placeholder="$t('pleaseEnterTheNumberOfPoints')"
            v-model="Points"
            type="number"
            pattern="[0-9]*"
          />
          <div class="closeInputBtn float-right">
            <img src="../assets/img/close.png" @click="resetPoints()" />
          </div>
        </div>
        <div class="inputCon clearfix">
          {{$t('remarks')}}：
          <!-- <textarea v-model="Remarks" maxlength="60">{{$t('pleaseEnterANoteMessage')}}</textarea> -->
          <input
            class
            :placeholder="$t('pleaseEnterANoteMessage')"
            v-model="Remarks"
            maxlength="60"
          />
          <div class="closeInputBtn float-right">
            <img src="../assets/img/close.png" @click="resetRemarks()" />
          </div>
        </div>
        <div class="redEnvelopesBtnCon">
          <div class="redEnBtnCen">{{$t('envelopeWillBeRefundedAt24')}}</div>
          <!-- <div
            class="redEnBtnBot blueBtnPic buttonAll ContentFont"
            @click="redSend()"
          >{{$t('intoARedEnvelope')}}</div>-->
          <!-- c711 -->
          <div class="sendBtn blueBtnShare" style="width:170px; height:64px;">
            <button
              type="submit"
              @click="redSend()"
              style="background:none; border:none;"
            >{{$t('intoARedEnvelope')}}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
export default {
  components: {
    listTitle
  },
  data() {
    return {
      Points: '',
      Remarks: ''
    }
  },
  mounted() {},
  methods: {
    //紅包輸入框
    resetPoints() {
      this.Points = ''
    },
    //備註輸入框
    resetRemarks() {
      this.Remarks = ''
    },
    //塞入紅包按鈕
    redSend() {
      var roomId = this.$route.params.roomId
      var data = {
        room_id: roomId,
        type: 1,
        amount: this.Points,
        number: 1,
        remark: this.Remarks,
        cancel_hour: 24
      }
      var post = {
        info: data,
        url: 'api/redEnvelope/add'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          var redEnvelId = res.data.data
          window.location = '/RedLogin/' + redEnvelId
        } else {
          alert(res.data.message)
        }
      })
    }
  }
}
</script>
<style scoped>
</style>

