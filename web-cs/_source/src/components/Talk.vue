<template>
  <div class="all" id="content">
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="allContent talkAll" id="talkAll">
      <div class="talkChatCon" id="talkChatCon">
        <div
          class="talkDate talkHistory"
          @click="historyMessage()"
          onclick="ga('send', 'event', 'Talk', 'HistoryBtnClick', 'Talk');"
          v-if="historyPages===''&&historyCurrent===''&&hispages>1||historyPages!==historyCurrent"
        >{{$t("olderConversation")}}</div>

        <!-- 新對話 -->
        <div class="talkItem" v-for="(message,i) in messageList" id="talkItem">
          <div
            class="talkDate timeShow"
            v-if="i == 0 || messageList[i-1].created_at.substring(0,10) != message.created_at.substring(0,10) "
          >
            <span v-if="message.created_at.substring(0,10)===todayDate">{{$t('today')}}</span>
            <span v-else-if="message.created_at.substring(0,10)===yesterday">{{$t('yesterday')}}</span>
            <span v-else>{{message.created_at.substring(0,11)}}</span>
          </div>
          <div class="talkLeftCon clearfix">
            <div class="headPic" :class="message.is_self == 0?'float-right':'float-left'">
              <img
                :src="message.thumbnail== null ||message.thumbnail== 'null'||message.thumbnail== '' ?DefaultAvatar:message.thumbnail "
              />
            </div>
            <div class="chat" :class="message.is_self == 1?'float-left':'float-right'">
              <div class="groupTalk nameL" v-if="roomData.type ==2">{{message.name}}}</div>
              <!-- 聊天訊息 -->
              <div
                class="chatContent"
                :class="message.is_self == 1?'float-left':'float-right'"
                v-if="message.type ==1||message.type==2"
              >
                <!-- 洗分文字同意訊息 -->
                <span
                  class="wordwrap"
                  v-if="message.type == 1&&message.transaction_id&&message.transaction_status==1"
                >{{$t('userAcceptedRedEnvelope')}}{{$t('number')}}{{message.transaction_id}}</span>
                <!-- 洗分文字不同意訊息 -->
                <span
                  class="wordwrap"
                  v-else-if="message.type == 1&&message.transaction_id&&message.transaction_status==2"
                >{{$t('userHasRejectedRedEnvelope')}}{{$t('number')}}{{message.transaction_id}}</span>
                <!-- 一般文字訊息 -->
                <span class="wordwrap" v-else-if="message.type == 1">{{message.content}}</span>
                <!-- 圖片訊息 -->
                <span v-else-if="message.type ==2">
                  <viewer :images="message.content">
                    <img :src="message.content" />
                  </viewer>
                </span>
                <!-- <span v-else-if="message.type == 1&&message.transaction_id">111{{message.content}}</span> -->
              </div>
              <div
                :class="message.is_self == 1?'chatTimeRight':'chatTimeLeft'"
                v-if="message.type==1||message.type==2"
              >
                <span>{{message.created_at.substring(11,16)}}</span>
              </div>
              <!-- 聊天訊息結束 -->
              <!-- 紅包訊息 -->
              <div class="talkRedContent row" v-if="message.type==3">
                <div class="talkRedEnvel col-4">
                  <div
                    v-if="message.red_envelope_status ==1 "
                    @click="EventMode!=1?redEnvelopes(message.red_envelope_id,message.is_self):''"
                  >
                    <img src="../assets/img/envelopes.png" />
                  </div>
                  <!-- 鎖分模式中無法領取紅包 -->
                  <!-- <div v-if="message.red_envelope_status ==1 && EventMode==1">
                    <img src="../assets/img/envelopes.png" />2
                  </div>-->
                  <div v-else>
                    <img
                      src="../assets/img/envelopesOpen.png"
                      @click="redcheck(message.red_envelope_id,message.is_self)"
                    />
                  </div>
                </div>
                <div class="talkRedName col-8">
                  <div class="nameTop"></div>
                  <div class="namemsg">{{message.content}}</div>
                </div>
              </div>
              <div class="talkRedBottom clearfix" v-if="message.type==3">
                <div
                  class="float-left"
                  :class="message.red_envelope_status == 1 ?'talkRedBottomFontR':'talkRedBottomFontG'"
                >
                  <!-- <span 

                    v-if="message.red_envelope_status == 1"
                    @click="redEnvelopes(message.red_envelope_id,message.is_self) "
                  >{{$t('receivingARedEnvelope')}}</span>-->
                  <!-- <span v-else-if="message.red_envelope_status == 2">{{$t('received')}}</span> -->
                  <!-- 已領取 -->
                  <span v-if="message.red_envelope_status == 2">{{$t('received')}}</span>
                  <!-- 已取消 -->
                  <span v-else-if="message.red_envelope_status == 3">{{$t('cancelled')}}</span>
                  <!-- 已過期 -->
                  <span v-else-if="message.red_envelope_status == 4">{{$t('expired')}}</span>
                  <!-- 看內容 -->
                  <span
                    class="talkRedBottomFontR"
                    v-else-if="message.red_envelope_status == 1 && message.is_self==0"
                    @click="redEnvelopes(message.red_envelope_id,message.is_self) "
                  >{{$t('watchContent')}}</span>
                  <!-- 領取紅包 -->
                  <span
                    class="talkRedBottomFontR"
                    v-else-if="EventMode!=1"
                    @click="redEnvelopes(message.red_envelope_id,message.is_self) "
                  >{{$t('receivingARedEnvelope')}}</span>
                  <span class="talkRedBottomFontR" v-else-if="EventMode==1">{{$t('eventMode')}}</span>
                </div>
                <!-- 收回 -->
                <div
                  class="float-right redcancel"
                  v-if="message.red_envelope_status == 1 && message.is_self==0"
                  @click="cancelRedEnvelope(message.red_envelope_id)"
                  id="cancelRed"
                >{{$t('cancelRed')}}</div>
              </div>

              <div
                :class="message.is_self == 1?'chatRedTimeRight':'chatRedTimeLeft'"
                v-if="message.type==3"
              >
                <span>{{message.created_at.substring(11,16)}}</span>
              </div>
              <!-- 紅包訊息結束 -->
              <!-- 客服洗分 -->
              <!-- 左 -->
              <div class="talkRedContent row" v-if="message.type==6 ">
                <div class="talkRedEnvel col-4">
                  <div>
                    <img src="../assets/img/CS.png" />
                  </div>
                </div>
                <div class="talkRedName col-8">
                  <div class="nameTop"></div>
                  <div
                    class="namemsg"
                  >{{$t('customerServiceTransfer')}} {{message.content}} {{$t('point')}}{{$t('notice')}}</div>
                </div>
              </div>

              <div class="talkRedBottom clearfix" v-if="message.type==6">
                <div class="float-left csColor">
                  <span v-if="message.transaction_status == 3">{{$t('expired')}}</span>
                  <span v-else-if="message.transaction_status == 1">{{$t('approved')}}</span>
                  <span v-else-if="message.transaction_status == 2">{{$t('rejected')}}</span>
                  <span
                    class="csFontSize"
                    v-else-if="message.transaction_status == 4"
                    @click="agree(message.transaction_id,message.content)"
                  >{{$t('yes')}}</span>
                </div>
                <div class="float-right csColor csNo">
                  <span v-if="message.transaction_status == 3"></span>
                  <span v-else-if="message.transaction_status == 1"></span>
                  <span v-else-if="message.transaction_status == 2"></span>
                  <span
                    class="csFontSize"
                    v-else-if="message.transaction_status == 4"
                    @click="disagree(message.transaction_id,message.content)"
                  >{{$t('no')}}</span>
                </div>
              </div>
              <div
                :class="message.is_self == 1?'chatRedTimeRight':'chatRedTimeLeft'"
                v-if="message.type==6"
              >
                <span>{{message.created_at.substring(11,16)}}</span>
              </div>
              <!-- 客服洗分結束 -->

              <!-- 客服開分 -->

              <div class="talkRedContentS row" v-if="message.type==7||message.type==8">
                <div class="talkRedEnvel col-4">
                  <div>
                    <img src="../assets/img/CSR.png" />
                  </div>
                </div>
                <div class="talkRedName col-8">
                  <div class="nameTop"></div>
                  <div class="namemsg">
                    <span
                      v-if="message.type == 7"
                    >{{$t('customerServiceTransferO')}} {{message.content}} {{$t('point')}}</span>
                    <span
                      v-if="message.type == 8"
                    >{{$t('customerServiceTransferOGiftPoint')}} {{message.content}} {{$t('point')}}</span>
                  </div>
                </div>
              </div>

              <div
                :class="message.is_self == 1?'chatRedTimeRight':'chatRedTimeLeft'"
                v-if="message.type==7||message.type==8"
              >
                <span>{{message.created_at.substring(11,16)}}</span>
              </div>
              <!-- 客服開分結束 -->
            </div>
          </div>
        </div>
      </div>
      <!-- 訊息選單 -->
      <!-- C711 -->
      <!-- v-if="talkMenu" v-show="isShow" -->
      <div class="talkMenu talkMenuBg clearfix" v-show="isShow">
        <div class="talkMenuItem float-left">
          <label style="padding-top:5px;">
            <div class="menuBtnBg">
              <img src="../assets/img/camera.png" />
            </div>
            <div class="menuFont">{{$t("camera")}}</div>
            <input
              type="file"
              @change="uploadImg"
              id="camera"
              capture="camera"
              style="display:none"
              onclick="ga('send', 'event', 'Talk', 'CameraBtnClick', 'Talk');"
            />
          </label>
        </div>
        <div class="talkMenuItem float-left">
          <label style="padding-top:5px;">
            <div class="menuBtnBg">
              <img src="../assets/img/photo.png" />
            </div>
            <div class="menuFont">{{$t("photo")}}</div>
            <input
              type="file"
              @change="uploadImg"
              accept="image/*"
              style="display:none"
              onclick="ga('send', 'event', 'Talk', 'PhotoBtnClick', 'Talk');"
            />
          </label>
        </div>
        <div
          class="talkMenuItem float-left"
          onclick="ga('send', 'event', 'Talk', 'SoundBtnClick', 'Talk');"
        >
          <div class="menuBtnBg">
            <img src="../assets/img/microphone.png" class="soundred" />
          </div>
          <div class="menuFont">{{$t("sound")}}</div>
        </div>

        <div class="talkMenuItem float-left" id="RedEnvelopes">
          <a
            :href="/RedEnvelopes/+roomData.id"
            onclick="ga('send', 'event', 'Talk', 'RedEnvelopeBtnClick', 'Talk');"
          >
            <div class="menuBtnBg">
              <img src="../assets/img/envelope.png" class="soundred" />
            </div>
            <div class="menuFont">{{$t("redEnvelope")}}</div>
          </a>
        </div>
      </div>
      <div class="talkBottom talkBottomBg clearfix">
        <div class="bottomLeftRight float-left">
          <img :src="menuBtnSrc" @click="talkMenuClick()" class="menu711" />
        </div>
        <!-- 訊息輸入 -->
        <div class="bottomInput float-left">
          <!-- <input
            type="text"
            class="massageInput"
            v-model="massageInput"
            @keyup.enter.exact="sendMessage(1)"
          />-->
          <!-- @keyup.ctrl.enter="lineFeed()" -->
          <textarea style="overflow:auto" class="massageInput" v-model="massageInput"></textarea>
        </div>
        <div class="bottomLeftRight float-left" @click="sendMessage(1)">
          <button id="sendBtn" style="background:none; border:none;outline:none;">
            <img src="../assets/img/path.png" class="menupath" />
          </button>
        </div>
      </div>
    </div>
    <div class="mask redEvelModal" v-if="redEnvelopesModal">
      <div class="redEvelCon">
        <img src="../assets/img/redEnvelopes3.png" />
        <div class="redClose" @click="closeredModal()">
          <img src="../assets/img/redClose.png" />
        </div>
        <div class="redCon">
          <div class="redPic">
            <img
              :src="redEnvelList.sender_thumbnail == null||redEnvelList.sender_thumbnail == 'null'||redEnvelList.sender_thumbnail == ''?DefaultAvatar:redEnvelList.sender_thumbnail"
            />
          </div>
          <!-- <div class="redName">Aaron</div> -->
          <div class="redTalk" v-if="isSelf == 0">{{$t('youSentARedEnvelope')}}</div>
          <div class="redTalk" v-else>{{$t('redEnvelopeForYou')}}</div>
        </div>
        <div
          class="redOpenFont"
          @click="SenderEnvContent(redEnvelList.red_envelope_id)"
          v-if="isSelf == 0"
        >{{$t('watchContent')}}</div>
        <div
          class="redOpenFont"
          @click="OpenredEnvModal(redEnvelList.remark)"
          v-else
        >{{$t('redEnvelopeOpen')}}</div>
      </div>
    </div>
    <!-- 紅包打開 -->
    <!--   -->
    <div class="mask redEvelModal" v-if="redEnvOpenModal">
      <div class="redEvelCon">
        <img src="../assets/img/BenvelopesOpen3.png" id="openRed" />
        <img src="../assets/img/openRed3.gif" class="openRedAC" id="openRedAC" />
        <div class="redClose" @click="closeOpnModal()">
          <img src="../assets/img/redClose.png" />
        </div>
        <div class="redCon">
          <div class="redPic">
            <img :src="sendPic == null||sendPic == 'null'||sendPic == ''?DefaultAvatar:sendPic" />
          </div>
          <div class="redOpenCon">{{remark}}</div>
          <div class="redOpenPoint">{{redamount}}</div>
        </div>
        <!-- <a href="/RedReceive"> -->
        <div class="redOpenDetails" @click="recordList()">
          {{$t('viewRecordDetails')}}
          <img src="../assets/img/sright.png" />
        </div>
        <!-- </a> -->
      </div>
    </div>
    <!-- //紀錄 -->
    <div class="mask recordModal" v-if="recordListModal">
      <div class="listTitleHeight">
        <div class="titleBg titleHeaderBg">
          <div class="titleContent friendtalkTitle clearfix">
            <!--  -->
            <div
              class="float-left friendtalkMenu selectAction"
              :class="sendReceive==1 ? 'selectBtn':''"
              @click="senderRed(1)"
            >
              <div class="friendtalkFont titleFontColor">{{$t('receiveRed')}}</div>
            </div>
            <div
              class="float-left friendtalkMenu selectAction"
              :class="sendReceive==2||sendReceive=='' ? 'selectBtn':''"
              @click="senderRed(2)"
            >
              <div class="friendtalkFont titleFontColor">{{$t('sendRed')}}</div>
            </div>
            <!-- <a :href="/TalkByCode/+chatCode"> -->
            <div class="float-right" @click="closerecordBtn()">
              <img src="../assets/img/redClose.png" />
            </div>
            <!-- </a> -->
          </div>
        </div>
      </div>
      <div class="recordCon">
        <div class="recordList">
          <div class="recordListCon">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th scope="col">{{$t('date')}}</th>
                  <th scope="col">{{$t('friend')}}</th>
                  <th scope="col">{{$t('type')}}</th>
                  <th scope="col">{{$t('vpoint')}}</th>
                </tr>
              </thead>
              <tbody>
                <!-- 領取 -->
                <tr v-for="(redEnvel,i) in redEnvelList" v-if="sendReceive ==1 ">
                  <td>{{redEnvel.receive_time ? redEnvel.receive_time:$t('notYetReceived')}}</td>
                  <td>{{redEnvel.receiver_username ? redEnvel.receiver_username:$t('notYetReceived')}}</td>
                  <td>{{redEnvel.type == 1? $t('personalRedEnvelope') :(redEnvel.type == 2)?$t('groupRedEnvelope'):$t('random')}}</td>
                  <td>{{redEnvel.amount}}</td>
                </tr>
                <!-- 發送 -->
                <tr v-for="(redEnvel,i) in redEnvelList" v-if="sendReceive ==2 ||sendReceive ==''">
                  <td>{{redEnvel.send_time}}</td>
                  <td>{{redEnvel.sender_username ? redEnvel.sender_username:$t('notYetReceived')}}</td>
                  <td>{{redEnvel.type == 1? $t('personalRedEnvelope') :(redEnvel.type == 2)?$t('groupRedEnvelope'):$t('random')}}</td>
                  <td>{{redEnvel.amount}}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
import redRHeader from '@/components/redRecordHeader'
import 'viewerjs/dist/viewer.css' //圖片瀏覽
import 'lrz/dist/lrz.bundle' //圖片壓縮
export default {
  components: {
    listTitle,
    redRHeader
  },

  data() {
    return {
      isShow: false,
      talkMenu: false,
      deleteModal: false,
      menuBtnSrc: require('../assets/img/bottomMenu.png'),
      messageList: '',
      roomData: '',
      massageInput: '',
      DefaultAvatar: require('../assets/img/user.jpg'),
      roomCode: '',
      historyMsgList: '',
      page: 2,
      messagePic: '',
      redEnvelopesModal: false,
      redEnvOpenModal: false,
      redEnvelList: '',
      redNumber: '',
      remark: '',
      redamount: '',
      sendPic: '',
      isSelf: '',
      recordListModal: false,
      redEnvelList: '',
      sendReceive: '',
      uploadPic: '',
      talkTime: '',
      oldTalkTime: '',
      timeShow: '',
      todayDate: '',
      yesterday: '',
      historyPages: '',
      historyCurrent: '',
      hispages: '',
      //   取平台鎖分模式
      EventMode: sessionStorage.getItem('EventMode')
    }
  },
  mounted() {
    //   取平台鎖分模式 紅包功能
    if (this.EventMode == 1) {
      $('#RedEnvelopes').css('display', 'none')
    }
    //   抓今天/昨天日期
    var Today = new Date()
    this.todayDate =
      Today.getFullYear() + '-' + (Today.getMonth() + 1) + '-' + Today.getDate()
    this.yesterday =
      Today.getFullYear() +
      '-' +
      (Today.getMonth() + 1) +
      '-' +
      (Today.getDate() - 1)

    // console.log(this.todayDate, 'today')
    // console.log(this.yesterday, 'yesterday')
    //IOS輸入問題
    // var el = $('.massageInput')
    // el.on('keydown', function() {
    //   window.focus()
    //   el.focus()
    // })

    //聊天內容DIV的高度
    const vm = this
    vm.fullHeight = window.innerHeight
    window.onresize = () => {
      vm.fullHeight = window.innerHeight
    }
    $('.talkChatCon').css('height', vm.fullHeight - 118 + 'px')

    //scrollbar保持在最底部
    var div = document.getElementById('talkChatCon')
    div.scrollTop = div.scrollHeight
    setTimeout(function() {
      div.scrollTop = div.scrollHeight
    }, 500)

    //進入方式TALKCODE/FRIENDID
    var talk = this.$route.params
    sessionStorage.setItem('chatCode', talk.talkcode)
    sessionStorage.setItem('friendId', talk.friendId)
    if (this.$route.meta.by == 'code') {
      var data = {
        code: talk.talkcode
      }
    } else {
      var data = {
        friend_id: talk.friendId
      }
    }
    var post = {
      info: data,
      url: 'api/room/enter'
    }
    var messageLine = this.messageLine
    this.$store.dispatch('post', post).then(res => {
      if (res.data.code == 1000) {
        // console.log(res.data, 'room/enter')
        this.roomCode = res.data.data.room.code
        this.roomData = res.data.data.room
        this.messageList = res.data.data.message
        // console.log('messageList', this.messageList)
        var msgList = this.messageList
        // console.log(res.data.data.message, 'roomqCode')
        $('.titleCenter').html(this.roomData.name)
        //抓有無歷史訊息
        var data = {
          code: this.roomCode,
          page: this.page,
          per_page: 30
        }
        var post = {
          info: data,
          url: 'api/room/history'
        }
        this.$store.dispatch('post', post).then(res => {
          if (res.data.code == 1000) {
            var hispages = res.data.pagination.pages
            this.hispages = hispages
            // console.log(this.hispages, '頁數')
          } else {
            alert(res.data.message)
          }
        })

        //監聽
        //接收所有傳送到包含本人的聊天室訊息
        var channel = window.Echo.private(
          'newMessage.1dad4f95fb79d0dc5a93c43c1faaab6c'
        )
        channel.listen('NewMessage', function(data) {
          //   console.log(data)
        })
        //接收傳送到這個聊天室的訊息
        var channel2 = window.Echo.private('room.' + this.roomCode)
        channel2.listen('MessageSent', function(data) {
          //   console.log(data, '監聽在這')

          //判斷是不是自己傳送的訊息
          var is_self =
            sessionStorage.getItem('memberID') == data.message.member_id ? 0 : 1
          var value = {
            is_self: is_self,
            content: data.message.content,
            thumbnail: data.message.thumbnail,
            created_at: data.message.time,
            type: data.message.type,
            red_envelope_status: 1, //紅包送出是關閉的
            red_envelope_id: data.message.red_envelope_id,
            transaction_status: data.message.transaction_status,
            transaction_id: data.message.transaction_id
          }
          //   將訊息加到聊天室的最後面
          msgList.push(value)
          //   console.log(data.message, 'yyyy')
          //即時訊息跳出
          setTimeout(function() {
            let chatScroll = document.getElementById('talkChatCon')
            let element = document.getElementById('talkChatCon').firstChild
            let h = 99999999
            chatScroll.scroll({
              top: h
            })
          }, 300)
        })
      } else {
        alert(res.data.message)
      }
    })
    //會員帳號資訊
    var memberdata = {}
    var post = {
      info: memberdata,
      url: 'api/member/self'
    }
    this.$store.dispatch('post', post).then(res => {
      if (res.data.code == 1000) {
        var memberSelf = res.data.data
        sessionStorage.setItem('memberID', memberSelf.id)
      } else {
        alert(res.data.message)
      }
    })
  },

  methods: {
    //TALK選單
    talkMenuClick: function() {
      this.isShow = !this.isShow
      if (this.isShow) {
        ;(this.talkMenu = true),
          (this.menuBtnSrc = '../../static/img/bottomMenu2.png')
      } else {
        ;(this.talkMenu = false),
          (this.menuBtnSrc = '../../static/img/bottomMenu.png')
      }
    },
    //發送圖片訊息
    uploadImg(obj) {
      //   console.log(obj)
      var file = obj.target.files[0]
      //   console.log(file, 'file')
      //   console.log(file.type, 'filetype')
      //判斷型別是不是圖片
      if (!/image\/\w/.test(file.type)) {
        alert('請確保檔案為影象型別')
        return false
      }
      var reader = new FileReader()
      reader.readAsDataURL(file)
      let vm = this
      reader.onload = function(e) {
        vm.dealImage(this.result, { width: 1920 }, function(base) {
          //   console.log(base, '值')
          vm.uploadPic = base
          var data = {
            code: vm.roomCode,
            type: 2,
            content: vm.uploadPic
          }
          var post = {
            info: data,
            url: 'api/sendMessage'
          }
          vm.$store.dispatch('post', post).then(res => {
            if (res.data.code == 1000) {
              //   console.log('res', res.data.data)
              this.talkMenu = false
              this.menuBtnSrc = '../../static/img/bottomMenu.png'
            } else {
              alert(res.data.message)
            }
          })
        })
      }
      this.talkMenu = false
      this.menuBtnSrc = '../../static/img/bottomMenu.png'

      /**
       * 圖片壓縮，預設同比例壓縮
       * @param {Object} path
       * pc端傳入的路徑可以為相對路徑，但是在移動端上必須傳入的路徑是照相圖片儲存的絕對路徑
       * @param {Object} obj
       * obj 物件 有 width， height， quality(0-1)
       * @param {Object} callback
       * 回撥函式有一個引數，base64的字串資料
       */
    },
    dealImage(path, obj, callback) {
      var img = new Image()
      img.src = path
      img.onload = function() {
        var that = this
        // 預設按比例壓縮
        var w = that.width,
          h = that.height,
          scale = w / h
        w = obj.width || w
        h = obj.height || w / scale
        var quality = 0.7 // 預設圖片質量為0.7
        //生成canvas
        var canvas = document.createElement('canvas')
        var ctx = canvas.getContext('2d')
        // 建立屬性節點
        var anw = document.createAttribute('width')
        anw.nodeValue = w
        var anh = document.createAttribute('height')
        anh.nodeValue = h
        canvas.setAttributeNode(anw)
        canvas.setAttributeNode(anh)
        ctx.drawImage(that, 0, 0, w, h)
        // 影象質量
        if (obj.quality && obj.quality <= 1 && obj.quality > 0) {
          quality = obj.quality
        }
        // quality值越小，所繪製出的影象越模糊
        var base64 = canvas.toDataURL('image/jpeg', quality)
        // 回撥函式返回base64的值
        callback(base64)
      }
    },

    //發送訊息
    sendMessage(type) {
      if (this.massageInput == '') {
        alert(this.$t('pleaseEnteAMessage'))
      } else {
        var talk = this.$route.params
        let massageInput = this.massageInput

        var data = {
          code: this.roomCode,
          type: type,
          content: massageInput
        }
        var post = {
          info: data,
          url: 'api/sendMessage'
        }
        this.$store.dispatch('post', post).then(res => {
          if (res.data.code == 1000) {
            // console.log('res', res)
            this.massageInput = ''
          } else {
            alert(res.data.message)
          }
        })
      }
    },
    //取歷史訊息
    historyMessage() {
      var messageLine = this.messageLine
      var msgList = this.messageList
      var talk = this.$route.params
      var data = {
        code: this.roomCode,
        page: this.page,
        per_page: 30
      }
      var post = {
        info: data,
        url: 'api/room/history'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.historyMegList = res.data.data
          this.pagination = res.data.pagination
          this.historyPages = this.pagination.pages
          this.historyCurrent = this.pagination.current
          this.historyNext = this.pagination.next
          //   console.log(this.historyPages, this.historyCurrent, '頁數2')
          var historyMegList = this.historyMegList
          var heightTop = 0
          var is_self =
            sessionStorage.getItem('username') == historyMegList.username
              ? 0
              : 1
          var value = {
            is_self: is_self,
            content: historyMegList.content,
            thumbnail: historyMegList.thumbnail,
            created_at: historyMegList.time,
            type: historyMegList.type
          }
          $.each(res.data.data, function(key, value) {
            heightTop += 37
            msgList.unshift(value)
            // $('.talkChatCon').prepend(messageLine(value))
          })
          $('.talkChatCon').scrollTop(heightTop)
          this.page++
        } else {
          alert(res.data.message)
        }
      })
    },
    //紅包確認
    redcheck(id, isSelf) {
      //   console.log('紅包')
      var redNum = id
      this.isSelf = isSelf
      this.redNumber = redNum
      //   this.redEnvelopesModal = true
      this.redEnvOpenModal = true

      var data = {
        red_envelope_id: id
      }
      var post = {
        info: data,
        url: 'api/redEnvelope/view'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.redEnvelList = res.data.data
          var redEnvelList = this.redEnvelList
          this.redamount = redEnvelList.amount
          //   console.log(redEnvelList.sender_thumbnail, '照片再這')
          this.sendPic = redEnvelList.sender_thumbnail
          $('#openRed').css('opacity', '0')
          setTimeout(() => {
            $('#openRedAC').css('display', 'none')
            $('#openRed').css('opacity', '1')
          }, 4100)
        } else {
          alert(res.data.message)
        }
      })
    },
    //紅包信封跳出
    redEnvelopes(id, isSelf) {
      var redNum = id
      this.isSelf = isSelf
      this.redNumber = redNum
      this.redEnvelopesModal = true
      var data = {
        red_envelope_id: id
      }
      var post = {
        info: data,
        url: 'api/redEnvelope/view'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.redEnvelList = res.data.data
          var redEnvelList = this.redEnvelList
          this.redamount = redEnvelList.amount
          //   console.log(redEnvelList.sender_thumbnail, '照片再這')
          this.sendPic = redEnvelList.sender_thumbnail
        } else {
          alert(res.data.message)
        }
      })
    },
    closeredModal() {
      this.redEnvelopesModal = false
    },
    //打開紅包
    OpenredEnvModal(remark) {
      this.remark = remark
      //   console.log(this.remark, '備註')
      this.redEnvelopesModal = false
      //   console.log(this.redNumber, '有紅包id嗎')
      var data = {
        red_envelope_id: this.redNumber,
        remark: remark
      }
      var post = {
        info: data,
        url: 'api/redEnvelope/edit'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.redEnvOpenModal = true
          $('#openRed').css('opacity', '0')
          setTimeout(() => {
            $('#openRedAC').css('display', 'none')
            $('#openRed').css('opacity', '1')
          }, 4100)
        } else {
          alert(res.data.message)
          this.redEnvOpenModal = false
          window.location.reload()
        }
      })
    },
    //發送者看紅包內容
    SenderEnvContent(redId) {
      this.redEnvelopesModal = false
      this.redEnvOpenModal = true
      $('#openRed').css('opacity', '0')
      setTimeout(() => {
        $('#openRedAC').css('display', 'none')
        $('#openRed').css('opacity', '1')
      }, 4100)
    },
    //關閉打開紅包
    closeOpnModal() {
      this.redEnvOpenModal = false
      window.location.reload()
    },
    //打開紅包紀錄
    recordList() {
      this.recordListModal = true
      var data = {}
      var url = 'api/member/self'
      var post = {
        info: data,
        url: url
      }
      var store = this.$store
      store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          var memberData = res.data.data
          var data = {
            member_id: memberData.id,
            action: 1,
            page: 1,
            per_page: 20
          }
          var url = 'api/redEnvelope/list'
          var post = {
            info: data,
            url: url
          }
          store.dispatch('post', post).then(res => {
            if (res.data.code == 1000) {
              this.redEnvelList = res.data.data
            } else {
              alert(res.data.message)
            }
          })
        } else {
          alert(res.data.message)
        }
      })
    },
    //關閉紅包紀錄
    closerecordBtn() {
      this.recordListModal = false
      this.redEnvOpenModal = false
      window.location.reload()
    },
    //發送紅包紀錄
    senderRed(sendReceive) {
      this.sendReceive = sendReceive
      var data = {}
      //會員帳號資訊
      var url = 'api/member/self'
      var post = {
        info: data,
        url: url
      }
      var store = this.$store
      store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          //紅包列表
          var memberData = res.data.data
          var data = {
            member_id: memberData.id,
            action: 1,
            page: 1,
            per_page: 20
          }
          var url = 'api/redEnvelope/list'
          var post = {
            info: data,
            url: url
          }
          store.dispatch('post', post).then(res => {
            if (res.data.code == 1000) {
              this.redEnvelList = res.data.data
            } else {
              alert(res.data.message)
            }
          })
        } else {
          alert(res.data.message)
        }
      })
    },
    //紅包收回
    cancelRedEnvelope(id) {
      $('#cancelRed').attr('disabled', 'true')
      var redEnvId = id
      var data = {
        red_envelope_id: redEnvId
      }
      var post = {
        info: data,
        url: 'api/redEnvelope/cancel'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          alert(this.$t('redRevoke'))
          window.location.reload()
        } else {
          alert(res.data.message)
        }
      })
    },
    //同意洗分
    agree(id, amount) {
      var transaction_id = id

      var amount = amount
      //   console.log(transaction_id)
      var data = {
        transaction_id: transaction_id,
        status: 1
      }
      var post = {
        info: data,
        url: 'api/transaction/confirm'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          ga('send', 'event', {
            eventCategory: 'member_Transfer',
            eventAction: 'TransFer_In_Click',
            eventLabel: transaction_id,
            eventValue: amount
          })
          //   console.log(member_id, amount, '同意')
          window.location.reload()
        } else {
          alert(res.data.message)
        }
      })
    },
    //不同意洗分
    disagree(id, amount) {
      var transaction_id = id

      var amount = amount
      var data = {
        transaction_id: transaction_id,
        status: 2
      }
      var post = {
        info: data,
        url: 'api/transaction/confirm'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          ga('send', 'event', {
            eventCategory: 'member_Transfer',
            eventAction: 'TransFer_Out_Click',
            eventLabel: transaction_id,
            eventValue: amount
          })
          window.location.reload()
        } else {
          alert(res.data.message)
        }
      })
    }
    // 換行
    // lineFeed() {
    //   console.log('换行')
    //   console.log(this.massageInput)
    //   this.massageInput = this.massageInput + '\n'
    // }
  },
  watch: {
    //新訊息在最下方
    massageInput() {
      setTimeout(function() {
        let chatScroll = document.getElementById('talkChatCon')
        let element = document.getElementById('talkChatCon').firstChild
        let h = 99999999
        chatScroll.scroll({
          top: h
        })
      }, 300)
    }
  }
}
</script>
<style scoped>
</style>

