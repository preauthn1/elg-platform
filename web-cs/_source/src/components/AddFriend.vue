<template>
  <div class="all" id="content">
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="allContent listbgPic" style="height:100vh;">
      <div class="search clearfix">
        <div class="float-left searchinput">
          <input
            class="form-control inputbg"
            v-model="searchInput"
            id="Search"
            :placeholder="$t('searchFriendId')"
          />
        </div>
        <div class="float-right searchBtn">
          <i class="fa fa-lg fa-search searchicon" @click="searchFriendBtn()"></i>
        </div>
      </div>
      <!-- <div style="color:#fff;">{{searchInput}}</div> -->
      <!-- <div class="blueBtnLong">
        <div class="longBtnBg longBtnPic clearfix">
          <a href="/QRcodeSearch">
            <div class="float-left bluePic">
              <img src="../assets/img/qrcode.png" />
            </div>
            <div class="float-left settingFont contentFont">
              <span>{{$t("myQRCode")}}</span>
            </div>
            <div class="float-right blueBtnRight">
              <img src="../assets/img/right.png" />
            </div>
          </a>
        </div>
      </div>-->
    </div>
    <div class="addFriendModal mask mcontentBg" v-if="addFriendModal">
      <div class="modalContent addModalBg">
        <div class="addModalHeader addModalHeight modalHeaderBg mheaderBorder mheaderFont">
          <div class="addFriendTitle">
            <img
              :src="memberList.thumbnail=== null ||memberList.thumbnail=== 'null'||memberList.thumbnail=== '' ?DefaultAvatar:memberList.thumbnail "
            />
            {{memberList.nickname}}
          </div>
          <div class="closeBtn" @click="closeModal()">
            <img src="../assets/img/closeBtn.png" />
          </div>
        </div>
        <div class="addFriendBtn" v-if="this.memberList.status == '0'">
          <div class="addBtnOne" @click="addNewFriend()">
            <div class="friendBtn">
              <img src="../assets/img/addBtn.png" />
            </div>
            <div class="friendFont ContentFont">{{$t("addFriend")}}</div>
          </div>
        </div>
        <div class="addFriendBtn clearfix" v-if="this.memberList.status == '1'">
          <a :href="/TalkById/+memberList.friend_id">
            <div class="addBtnLeftRight float-left">
              <div class="friendBtn">
                <img src="../assets/img/talkBtn.png" />
              </div>
              <div class="friendFont ContentFont">{{$t("chat")}}</div>
            </div>
          </a>
          <div class="addBtnLeftRight float-left" @click="blockFriend()">
            <div class="friendBtn">
              <img src="../assets/img/lockBtn.png" />
            </div>
            <div class="friendFont ContentFont">{{$t("blackList")}}</div>
          </div>
        </div>
        <div class="addFriendBtn clearfix" v-if="this.memberList.status == '2'">
          <div class="addBtnLeftRight float-left" @click="deleteFriend()">
            <div class="friendBtn">
              <img src="../assets/img/deleteBtn.png" />
            </div>
            <div class="friendFont ContentFont">{{$t("delete")}}</div>
          </div>
          <div class="addBtnLeftRight float-left" @click="unblockFriend()">
            <div class="friendBtn">
              <img src="../assets/img/UnlockBtn.png" />
            </div>
            <div class="friendFont ContentFont">{{$t("unBlackList")}}</div>
          </div>
        </div>
      </div>
    </div>

    <div class="addFriendModal mask mcontentBg" v-if="addFriendModal4">
      <div class="modalContent addModalBg">
        <div
          class="addModalHeader promptHeight modalHeaderBg mheaderBorder mheaderFont"
        >{{$t("prompt")}}</div>
        <div class="addFriendPrompt ContentFont">{{$t("checkAccountTryAgain")}}</div>
        <div class="oneBtnCenter">
          <div class="modalBtn modalBtnGreen modalBtnFont" @click="promptClose()">{{$t("confirm")}}</div>
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
  props: {},
  data() {
    return {
      searchInput: '',
      addFriendModal: false,
      addFriendModal4: false,
      DefaultAvatar: require('../assets/img/user.jpg'),
      username: ''
    }
  },
  computed: {},
  mounted() {
    // if ($route.params.username != '') {
    //   this.searchFriendBtn(xxx)
    // }
    $('.titleCenter').html(this.$t('addFriends'))
  },
  methods: {
    //搜尋好友按鈕
    searchFriendBtn() {
      var username = this.searchInput
      if (username == '') {
        alert(this.$t('pleaseEnterAFriendId'))
      } else {
        let sign = sessionStorage.getItem('sign')
        if (sign == 'undefined') {
          sign = this.$route.params.sign
          sessionStorage.setItem('sign', sign)
        }
        if (sign != 'undefined') {
          var sign_data = JSON.parse(sign)
          var data = {
            USERNAME: sign_data.username,
            THUMBNAIL: sign_data.avatar,
            NICKNAME: sign_data.nickname,
            username: username
          }
          //   console.log(username, 'username')
          var post = {
            info: data,
            url: 'api/member/search'
          }
          this.$store.dispatch('post', post).then(res => {
            this.memberList = res.data.data
            // console.log(this.memberList, '會員')
            if (res.data.code == 1000) {
              if (this.memberList.status == '0') {
                this.addFriendModal = true
              } else if (this.memberList.status == '1') {
                this.addFriendModal = true
              } else if (this.memberList.status == '2') {
                this.addFriendModal = true
              }
            } else if (res.data.code == 1101) {
              this.addFriendModal4 = true
            } else {
              alert(res.data.message)
            }
          })
        } else {
          window.location = '/404'
        }
      }
    },
    //關閉家好友彈窗
    closeModal() {
      this.addFriendModal = false
    },
    //關閉提示彈窗
    promptClose() {
      this.addFriendModal4 = false
    },
    //加好友彈窗
    addNewFriend() {
      var data = {
        member_id: this.memberList.id
      }
      var url = 'api/friend/add'
      var post = {
        info: data,
        url: url
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.addFriendModal = false
          this.searchInput = ''
          alert('成功加入好友')
        } else {
          alert(res.data.message)
        }
      })
    },
    //刪除好友
    deleteFriend() {
      var data = {
        friend_id: this.memberList.friend_id
      }
      var url = 'api/friend/delete'
      var post = {
        info: data,
        url: url
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          alert(this.$t('deleteFriend'))
          this.searchInput = ''
        } else {
          alert(res.data.message)
        }
      })
    },
    //黑名單
    blockFriend() {
      var data = {
        friend_id: this.memberList.friend_id,
        status: 2
      }
      var post = {
        info: data,
        url: 'api/friend/edit'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.addFriendModal = false
        } else {
          alert(res.data.message)
        }
      })
    },
    //解除黑名單
    unblockFriend() {
      var data = {
        friend_id: this.memberList.friend_id,
        status: 1
      }
      var post = {
        info: data,
        url: 'api/friend/edit'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.addFriendModal = false
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

