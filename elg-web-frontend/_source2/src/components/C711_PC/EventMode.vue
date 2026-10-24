<template>
  <div>
    <div class="pageBg fontFamily CwidthHeight" style="padding-bottom:20px;">
      <EventsHeader></EventsHeader>
      <div class="pageCon">
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
                    src="../../../static/img/C711_PC/LOCKPIC.png"
                  />
                </div>
              </div>
              <!-- 可參加 -->
              <div
                id="joinBtn"
                style="width:40%;margin:auto;"
                v-if="EventMode!=1&&get_getLoginEvent.is_full!=1"
              >
                <div class="btn-blue" style=" width:120px; display:block">
                  <button @click="joinEventMode()" style="padding:5px;">{{$t('join')}}</button>
                </div>
              </div>
              <div id="inmode" style="text-align:center; color:red;">{{$t('eventModeOn')}}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <PopUp></PopUp>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import EventsHeader from '@/components/C711_PC/Events_header'
import PopUp from './Pop_up'
export default {
  computed: {
    ...mapGetters([
      'get_getLoginEvent',
      'get_checkEvent',
      'get_checkLoginEvent'
    ])
  },
  data() {
    return {
      EventMode: sessionStorage.getItem('eventMode')
    }
  },
  components: {
    EventsHeader,
    PopUp
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
    $('.footer_block').css('display', 'none')
  },
  methods: {
    // 參加鎖分活動
    joinEventMode() {
      //   帶參數三 PC版IFRAME參加活動
      this.$store.dispatch('actionJoinLoginEvent', 3)
      //   點參加後按鈕消失
      $('#joinBtn').css('display', 'none')

      if (this.EventMode == 1) {
        $('#inmode').css('display', 'block')
      }
    }
  }
}
</script>