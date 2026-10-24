<template>
  <div class="titleBg titleHeaderBg">
    <BlockUI v-show="spinnerStatus" style="z-index:99999">
      <i class="fa fa-cog fa-spin fa-3x fa-fw"></i>
    </BlockUI>
    <div class="titleContent clearfix">
      <a href></a>
      <div class="float-left titleLeftRight" @click="back()" v-if="$route.meta.back">
        <img src="../assets/img/back.png" class="leftrightPic" />
      </div>
      <div class="float-left titleLeftRight" @click="talkback()" v-if="$route.meta.talkback">
        <img src="../assets/img/back.png" class="leftrightPic" />
      </div>
      <div class="float-left titleCenter titleFontColor">{{ $t($route.meta.title) }}</div>
      <div class="float-left titleLeftRight">
        <img src="../assets/img/back.png" v-if="$route.meta.menu" class="leftrightPic" />
        <a href="javascript:;" v-if="$route.meta.next">
          <div class="titleFont titleFontColor">{{$t("next")}}</div>
        </a>
        <!-- <a href="/talk" class="btn blockDisable" v-if="$route.meta.unblock"> -->
        <button
          v-if="$route.meta.unblock"
          id="blockDisable"
          class="titleFont titleFontColor"
          style="border:none; background:none; font-size:10px; "
        >{{$t("unBlockList")}}</button>
        <!-- <div class="titleFont titleFontColor blockDisable">解除封鎖</div> -->
        <!-- </a> -->
        <a href="javascript:;" v-if="$route.meta.complete">
          <div class="titleFont titleFontColor">{{$t("complete")}}</div>
        </a>
        <a href="javascript:;" v-if="$route.meta.edit">
          <div class="titleFont titleFontColor">{{$t("edit")}}</div>
        </a>
        <a href="javascript:;" v-if="$route.meta.build">
          <div class="titleFont titleFontColor">{{$t("build")}}</div>
        </a>

        <!-- <img src="../assets/img/menuBtn.png" v-if="$route.meta.talkMenu" style="width:30px;" /> -->
        <div class="menuBtn" v-if="$route.meta.talkMenu">
          <!-- <img src="../assets/img/whiteMenu.png"  style="visibility:hidden"/> -->
          <Slide width="200" Position="right">
            <a href="/setName">
              <div class="slideFont contentFont">
                <span>{{$t("editNickname")}}</span>
              </div>
            </a>
            <a href="/searchTalk">
              <div class="slideFont contentFont">
                <span>{{$t("searchChat")}}</span>
              </div>
            </a>
            <div class="slideFont switchBtn contentFont" id="switchBtn" @click="switchNoClose()">
              <div class="switchFont contentFont">
                <span>{{$t("openReminder")}}</span>
              </div>
              <div class="switchBtnCh">
                <input type="checkbox" id="checkbox" class="checkbox" />
                <div class="checkbox-wrapper">
                  <label for="checkbox" class="checkbox-label"></label>
                </div>
              </div>
              <!-- <img :src="srcPic" class="switchPic" /> -->
            </div>
            <div class="slideFont contentFont" @click="openModal()">
              <span>{{$t("deleteChatRecord")}}</span>
            </div>
          </Slide>
        </div>
        <div class="menuBtn" v-if="$route.meta.groupChat">
          <!-- <img src="../assets/img/whiteMenu.png"  style="visibility:hidden"/> -->
          <Slide width="200" Position="right">
            <a href>
              <div class="slideFont contentFont">
                <span>{{$t("groupMember")}}</span>
              </div>
            </a>
            <a href>
              <div class="slideFont contentFont">
                <span>{{$t("addMember")}}</span>
              </div>
            </a>
            <a href>
              <div class="slideFont contentFont">
                <span>{{$t("groupName")}}</span>
              </div>
            </a>
            <a href>
              <div class="slideFont contentFont">
                <span>{{$t("transferOfManagement")}}</span>
              </div>
            </a>
            <div class="slideFont switchBtn contentFont" id="switchBtn" @click="switchNoClose()">
              <div class="switchFont contentFont">
                <span>{{$t("closeReminder")}}</span>
              </div>
              <div class="switchBtnCh">
                <input type="checkbox" id="checkbox" class="checkbox" />
                <div class="checkbox-wrapper">
                  <label for="checkbox" class="checkbox-label"></label>
                </div>
              </div>
              <!-- <img :src="srcPic" class="switchPic" /> -->
            </div>

            <div class="slideFont contentFont" @click="openModal()">
              <span>{{$t("dialogueRecord")}}</span>
            </div>
            <div class="slideFont contentFont" @click="dropOut()">
              <span>{{$t("exitGroup")}}</span>
            </div>
          </Slide>
        </div>
      </div>
    </div>
    <div class="mask talkModal" v-if="talkModal">
      <div class="talkModalAll">
        <div class="talkModalHeader modalHeaderBg mheaderBorder mheaderFont clearfix">
          {{$t("prompt")}}
          <img
            src="../assets/img/closeBtn.png"
            class="float-right"
            @click="closeModal()"
          />
        </div>
        <div class="talkModalCon">
          <div class="mbodyFont talkModalFont">{{$t("deleteTheConversationRecord")}}</div>
          <div class="talkModalfotter clearfix">
            <div class="modalBtn modalBtnGreen modalBtnFont float-left">{{$t("confirm")}}</div>
            <div
              class="modalBtn modalBtnRed modalBtnFont float-right"
              @click="closeModal()"
            >{{$t("cancel")}}</div>
          </div>
        </div>
      </div>
    </div>
    <div class="mask talkModal" v-if="dropOutModal">
      <div class="talkModalAll">
        <div class="talkModalHeader modalHeaderBg mheaderBorder mheaderFont clearfix">
          {{$t("prompt")}}
          <img
            src="../assets/img/closeBtn.png"
            class="float-right"
            @click="closeModal()"
          />
        </div>
        <div class="talkModalCon">
          <div class="mbodyFont talkModalFont">{{$t("confirmTheExitGroup")}}</div>
          <div class="talkModalfotter clearfix">
            <div class="modalBtn modalBtnGreen modalBtnFont float-left">{{$t("confirm")}}</div>
            <div
              class="modalBtn modalBtnRed modalBtnFont float-right"
              @click="closeModal()"
            >{{$t("cancel")}}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
//ios back reload
window.addEventListener('pageshow', function(e) {
  var isIOS = navigator.userAgent.indexOf('iPhone') > -1
  if (isIOS && localStorage.getItem('isNeedRefresh')) {
    localStorage.removeItem('isNeedRefresh')
    window.location.reload()
  }
})
import { Slide } from 'vue-burger-menu'
import axios from 'axios'
export default {
  components: { Slide },
  data() {
    return {
      talkModal: false,
      srcPic: require('../assets/img/switchBtnOpen.png'),
      srcPicClose: require('../assets/img/switchBtnOff.png'),
      dropOutModal: false,
      msg: 'Loading...',
      spinnerStatus: false
    }
  },
  mounted() {
    // var blockItem = sessionStorage.getItem('blockFriendId')
  },
  methods: {
    back() {
      window.history.go(-1)
    },
    talkback() {
      window.location = '/TalkList'
    },
    dropOut() {
      this.dropOutModal = true
    },
    openModal() {
      this.talkModal = true
    },
    closeModal() {
      this.talkModal = false
      this.dropOutModal = false
    },
    //點擊不要關閉
    switchNoClose() {
      $('#switchBtn').on('click', function(e) {
        e.stopPropagation()
      })
    }
    // checkBlock() {
    //   $('.disableBtn').prop('disabled', false)
    // }
  },
  created() {
    let $vue = this
    axios.interceptors.request.use(
      function(config) {
        $vue.spinnerStatus = true
        return config
      },
      function(error) {
        $vue.spinnerStatus = false
        return Promise.reject(error)
      }
    )
    // 使用setTimeout是為了demo效果，不然資料撈太快，會沒看到loading效果
    // 因此故意延遲3秒後才隱藏loading視窗
    axios.interceptors.response.use(
      function(response) {
        // setTimeout(function() {
        $vue.spinnerStatus = false
        // }, 1000)
        return response
      },
      function(error) {
        $vue.spinnerStatus = false
        return Promise.reject(error)
      }
    )
  }
}
</script>

<!-- Add "scoped" attribute to limit CSS to this component only -->
<style scoped>
</style>
