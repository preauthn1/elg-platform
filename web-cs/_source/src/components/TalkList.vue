<template>
  <div class="listbgPic listbgAll">
    <!-- <listTitle /> -->
    <div class="talkList allContent">
      <div class="talkGroup clearfix" v-for="(talk,i) in talkList" :key="talk.id">
        <a :href="'/TalkByCode/'+talk.code">
          <div class="talkPic float-left">
            <div class="talkPicCon">
              <img
                :src=" talk.thumbnail===null||talk.thumbnail==='null'||talk.thumbnail===''?DefaultAvatar:talk.thumbnail "
              />
            </div>
          </div>
          <div class="talkName ContentFont float-left">
            <div class="talkGropName">{{talk.name}}</div>
            <div class="talklr clearfix">
              <div
                class="talkContent talkListFont float-left"
                v-if="talk.message_type==1"
              >{{talk.message_content}}</div>
              <div class="talkContent talkListFont float-left" v-if="talk.message_type==2">
                <span v-if="talk.message_username==selfMem">{{$t('convey')}}</span>
                <span v-if="talk.message_username!==selfMem">{{$t('receive')}}</span>
                {{$t('imageMessage')}}
              </div>
              <div class="talkContent talkListFont float-left" v-if="talk.message_type==3">
                <span v-if="talk.message_username==selfMem">{{$t('convey')}}</span>
                <span v-if="talk.message_username!==selfMem">{{$t('receive')}}</span>
                {{$t('redEnvelopes')}}
              </div>
              <div
                class="talkContent talkListFont float-left"
                v-if="talk.message_type==6"
              >{{$t('transferIn')}}</div>
              <div
                class="talkContent talkListFont float-left"
                v-if="talk.message_type==7||talk.message_type==8"
              >{{$t('transferOut')}}</div>
              <div class="talkTime ContentFont float-right">{{talk.message_time}}</div>
            </div>
          </div>
        </a>
      </div>
    </div>
  </div>
</template>
<script>
import Global from '@/Global'
import md5 from 'js-md5'
export default {
  data() {
    return {
      talkList: '',
      DefaultAvatar: require('../../static/img/user.jpg'),
      cs: require('../../static/img/CS.png'),
      ag: require('../../static/img/CSR.png'),
      selfMem: sessionStorage.getItem('selfMember')
      //   customerServicePic: require('')
    }
  },
  mounted() {
    //GA(進頁面)
    // this.$ga.event({
    //   eventCategory: 'Points',
    //   eventAction: 'Enter',
    //   eventLabel: 'Points'
    //   //   eventValue: 123
    // })
    //  ga('send', 'event', {
    //     eventCategory: 'Points',
    //     eventAction: 'TransferOut_Click',
    //     eventLabel: this.vendor_name,
    //     eventValue: this.points
    //   })
    this.getRoomList()
    //會員帳號資訊
    var post = {
      info: {},
      url: 'api/member/self'
    }
    var getRooms = this
    var messageLine = this.messageLine
    this.$store.dispatch('post', post).then(res => {
      if (res.data.code == 1000) {
        // console.log(res, 'talklist')
        var memberData = res.data.data
        // console.log(memberData.third_party_id)
        sessionStorage.setItem('selfMember', memberData.third_party_id)
        var selfMember = sessionStorage.getItem('selfMember')
        // console.log(selfMember, 'sess')
        // 監聽
        // 接收所有傳送的新訊息加到列表
        let talkChannel = md5(
          memberData.id + Global.$API_MD5_KEY + memberData.username
        )
        var channel = window.Echo.private('newMessage.' + talkChannel)
        channel.listen('NewMessage', function(data) {
          //監聽 聊天列表加入到上方
          getRooms.getRoomList()
        })
      } else {
        alert(res.data.message)
        // console.log(res.data.message)
      }
    })
  },
  methods: {
    //聊天室列表
    getRoomList: function() {
      var data = {}
      var post = {
        info: data,
        url: 'api/room/list'
      }
      //   var messageLine = this.messageLine
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          //   console.log(res.data, 'resss')
          this.talkList = res.data.data
          var selfMember = sessionStorage.getItem('selfMember')
          //   console.log(selfMember, 'roomlist')
          //   console.log(this.talkList, '聊天列表')
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

