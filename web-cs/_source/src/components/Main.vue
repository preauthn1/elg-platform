<template>
  <div class="listbgPic listbgAll">
    <div class="friendList allContent ContentFont">
      <!-- {{this.$route.params.sign}} -->
      <!-- {{signEncrypt}} -->
      <div class="group" v-for="(friend,i) in friendList">
        <div
          class="topic ContentFont"
          v-if="i == 0 || friendList[i-1].type != friend.type "
        >{{friend.type == 0? $t("customerService") :(friend.type == 1)?$t("friend"):$t("group")}}</div>
        <div class="picName clearfix" @click="menuBtn(friend.friend_id)  ">
          <!-- 大頭照 -->
          <div class="groupPic float-left">
            <!-- <img :src="cs" v-if="friend.type == 0" /> -->
            <img
              :src=" friend.thumbnail=== null ||friend.thumbnail=== 'null' ||friend.thumbnail=== ''  ?DefaultAvatar:friend.thumbnail "
            />
          </div>
          <!-- 客服 -->
          <a :href="/TalkById/+friend.friend_id" v-if="friend.type == 0">
            <div class="groupName ContentFont float-left">{{friend.nickname}}</div>
          </a>
          <!-- 好友 -->
          <div class="groupName ContentFont float-left" v-if="friend.type == 1">{{friend.nickname}}</div>

          <!-- 朋友選單 -->
          <div
            class="personalMenu ContentFont"
            :id="friend.friend_id"
            v-if="friend.type == 1"
            style="display:none"
          >
            <div class="clearfix">
              <a :href="/TalkById/+friend.friend_id">
                <div class="blueMainBtn leftRadius menuBtnMain float-left">{{$t("chat")}}</div>
              </a>
              <a :href="'/SetName/'+friend.friend_id+'/'+friend.nickname">
                <div class="grayBtn menuBtnMain float-left">{{$t("nickname")}}</div>
              </a>
              <div
                class="blackBtn menuBtnMain float-left"
                id="blackBtn"
                @click="blackdeleteBtn(friend,'block')"
              >{{$t("blackList")}}</div>
              <div
                class="orangeBtn rightRadius menuBtnMain float-left"
                id="deleteBtn"
                @click="blackdeleteBtn(friend,'delete')"
              >{{$t("delete")}}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <div class="addFriendModal mask mcontentBg">
      <div class="modalContent addModalBg">
        <div class="addModalHeader mainModalHeight modalHeaderBg mheaderBorder mheaderFont">
          <div class="mainfriendTitle"></div>
          <div class="maincloseBtn" @click="closeModal()">
            <img src="../assets/img/closeBtn.png" />
          </div>
        </div>
        <div class="addFriendBtn ContentFont">
          <img src="../assets/img/game1.png" class="friendPic" />
          <span class="friendNickname"></span>
        </div>
        <div class="modalBtn modalBtnGreen modalBtnFont mainBtn" @click="submit()">{{$t("confirm")}}</div>
      </div>
    </div>
  </div>
</template>
<script>
import secret from '@/utils/secret'

export default {
  data() {
    return {
      beforeId: '',
      beforeType: '',
      personalMenu: false,
      DefaultAvatar: require('../../static/img/user.jpg'),
      ag: require('../../static/img/CSR.png'),
      cs: require('../../static/img/CS.png'),
      friendList: '',
      actionType: '',
      actionFriendId: ''
      //   signEncrypt: this.$route.params.sign
    }
  },
  mounted() {
    $('.addFriendModal').hide()

    //判斷是否有傳送簽名資訊
    let sign = this.$route.params.sign
    // 如果沒有就抓取SESSION的,有的話將SESSION取代
    if (typeof sign == 'undefined') {
      sign = sessionStorage.getItem('sign')
    } else {
      sign = secret.DecryptChat(sign.replace(/5s1g7er0A/g, '/'))
      //   console.log(sign, '解')
      //有就保存進SESSION
      sessionStorage.setItem('sign', sign)
    }
    //完全沒簽名就導至404
    if (typeof sign != 'undefined') {
      var sign_data = JSON.parse(sign)
      // 取平台鎖分模式
      //   console.log('sign_data ', sign_data)
      sessionStorage.setItem('EventMode', sign_data.EventMode)
      //   console.log('紀錄', sessionStorage.getItem('EventMode'))
      //紀錄langurage
      localStorage.setItem('LOCALE', sign_data.lang)
      this.$i18n.locale = sign_data.lang
      //保存USERNAME給之後使用
      sessionStorage.setItem('username', sign_data.username)
      //取平台template版本
      sessionStorage.setItem('Template', sign_data.templateMain)
      //   console.log(sessionStorage.getItem('Template'), '版本')

      //取好友列表
      var data = {
        USERNAME: sign_data.username,
        THUMBNAIL: sign_data.thumbnail,
        NICKNAME: sign_data.nickname,
        status: 1
      }
      var post = {
        info: data,
        url: 'api/friend/list'
      }
      this.$store.dispatch('post', post).then(res => {
        $('.personalMenu').hide()
        $('.addFriendModal').hide()
        if (res.data.code == 1000) {
          this.friendList = res.data.data
          //   console.log(this.friendList, 'friendList')
          $.each(this.friendList, function(i, friend) {
            // console.log(friend.friend_id, 'fade')
            // console.log('index:', i, friend.type, 'friendList2')
          })
        } else {
          alert(res.data.message)
        }
      })
    } else {
      window.location = '/404'
    }
  },
  methods: {
    menuBtn: function(id) {
      if (this.beforeId != id) {
        $('.personalMenu').hide()
        $('#' + id).show()
      } else {
        $('#' + id).toggle()
      }
      this.beforeId = id
    },
    //刪除,黑名單
    blackdeleteBtn: function(data, type) {
      $('.friendNickname').html(data.nickname)
      if (data.thumbnail == null || data.thumbnail == 'null') {
        $('.friendPic').attr('src', this.DefaultAvatar)
      } else {
        $('.friendPic').attr('src', data.thumbnail)
      }
      if (type == 'delete') {
        $('.mainfriendTitle').html(this.$t('deleteAFriend'))
      } else {
        $('.mainfriendTitle').html(this.$t('addBlockList'))
      }
      $('.addFriendModal').show()
      this.actionType = type
      this.actionFriendId = data.friend_id
    },
    //刪除好友
    submit() {
      if (this.actionType == 'delete') {
        var data = {
          friend_id: this.actionFriendId
        }
        var url = 'api/friend/delete'
      } else {
        var data = {
          friend_id: this.actionFriendId,
          status: 2
        }
        var url = 'api/friend/edit'
      }
      var post = {
        info: data,
        url: url
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          alert(this.$t('confirm'))
          window.location.reload()
        } else {
          alert(res.data.message)
        }
      })
    },
    closeModal() {
      $('.addFriendModal').hide()
    }
  }
}
</script>
<style scoped>
</style>

