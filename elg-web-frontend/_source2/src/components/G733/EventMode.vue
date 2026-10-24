<template>
  <div id="G733">
    <EventsHeader></EventsHeader>
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2">
          <div class="mainEvent">
            <div class="yellow_borderInput marginBottom20 marginTop20">
              <div class="black_EventDiv black_Divbg fontsizelogin">
                <div class="eventsTitle">
                  <div class="form" style="border:none">
                    <div
                      class="orangeTitle"
                      v-if="get_getLoginEvent.start_time&&get_getLoginEvent.end_time"
                    >
                      <div class="formTitle">{{$t('duringTheEvent')}}:</div>
                      {{get_getLoginEvent.start_time.substring(0, 16)}} ~ {{get_getLoginEvent.end_time.substring(0, 16)}}
                    </div>
                    <div class="orangeTitle">
                      <div class="formTitle">{{$t('participateQualification')}}:</div>
                      <div>1.{{$t('newMemberCreated')}}</div>
                      <div>2.{{$t('existingMembers')}}</div>
                      <div class="formTitle">{{$t('eventRules')}}:</div>
                      {{$t('youWillReceive')}}{{get_getLoginEvent.point}}{{$t('point')}}，{{$t('afterYouAgree')}}
                      <br />
                      <span>{{$t('theseAreTheCertain')}}</span>
                      <br />
                      1.{{$t('certainGamesAre')}}
                      <br />
                      2.{{$t('cannotOpenWithdrawal')}}
                      <br />
                      3.{{$t('connotJoinOrDelete')}}
                    </div>
                    <div class="orangeTitle">
                      <div class="formTitle">{{$t('youCanUnlock')}}</div>
                      <div>1.{{$t('balanceIsLesser')}}</div>
                      <div>2.{{$t('balanceIsMoreThen')}}{{get_getLoginEvent.point * get_getLoginEvent.times}}{{$t('point')}} {{$t('canKeepAll')}}</div>
                    </div>
                    <div class="orangeTitle">{{$t('youCanClickTheUnlock')}}</div>
                    <div class="redFont">* {{$t('attentionIfYou')}}</div>
                    <div class="orangeTitle">
                      {{$t('unlockButtonSign')}}&nbsp;
                      <img
                        :src="mobilepc=='MOBILE'?eventBtnPic_m:eventBtnPic_p"
                      />
                    </div>
                  </div>
                  <!-- 可參加 -->
                  <div
                    id="joinBtn"
                    class="footer"
                    v-if="EventMode!=1&&get_getLoginEvent.is_full!=1"
                  >
                    <div class="silverBorder btnBorder_all">
                      <button
                        type="submit"
                        @click="joinEventMode()"
                        class="redBtn btn_all"
                      >{{ $t("join") }}</button>
                    </div>
                  </div>
                  <!-- 活動模式中 -->
                  <div id="inmode" style="text-align:center; color:red;">{{$t('eventModeOn')}}</div>
                </div>
              </div>
            </div>
          </div>
          <!-- 彈窗 -->
          <maskModal></maskModal>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import EventsHeader from '@/components/G733/Events_header'
import maskModal from './Modal'
import { mapGetters } from 'vuex'
export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters([
      'get_getLoginEvent',
      'get_checkEvent',
      'get_checkLoginEvent'
    ])
  },
  data() {
    return {
      // 鎖分活動模式
      EventMode: sessionStorage.getItem('eventMode'),
      eventBtnPic_m: require('../../../static/img/G733/lockBTN.png'),
      eventBtnPic_p: require('../../../static/img/G733/lockBTN_p.jpg'),
      mobilepc: sessionStorage.getItem('PC_MOBILE')
    }
  },
  created() {
    this.$store.dispatch('actionGetLoginEvent')
  },
  mounted() {
    if (this.EventMode == 1) {
      $('#inmode').css('display', 'block')
    } else if (this.EventMode == 0) {
      $('#inmode').css('display', 'none')
    }
  },
  methods: {
    // 參加鎖分活動
    joinEventMode() {
      this.$store.dispatch('actionJoinLoginEvent', 1)
      //   點參加後按鈕消失
      $('#joinBtn').css('display', 'none')
      if (this.EventMode == 1) {
        //   如果 EVENTMODE = 1 顯示活動模式中
        $('#inmode').css('display', 'block')
      }
      //   抓IFRAME點JOININ 活動 IFRAME關閉RELOAD
      sessionStorage.setItem('eventIframe', 1)
    }
  }
}
</script>

