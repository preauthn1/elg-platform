<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="allContent talkAll">
      <div class="whiteCon">
        <!-- <button
          type="button"
          class="btn btn-primary"
          data-toggle="modal"
          data-target="#exampleModal"
        >Launch demo modal</button>-->
        <div class="talkDate">2019/07/01</div>
        <div class="talkItem">
          <!-- 左  -->
          <div class="talkLeftCon clearfix">
            <div class="headPic float-left">
              <img src="../assets/img/pic2.jpg" />
            </div>
            <div class="chat float-left">
              <div class="talkRedContent row">
                <div class="talkRedEnvel col-4">
                  <img src="../assets/img/envelopes.png" />
                </div>
                <div class="talkRedName col-8">
                  <div class="namemsg">發紅包給您</div>
                </div>
              </div>
              <!-- <div class="talkRedBottom talkRedBottomFontR" @click="redEnvelopes()">領取紅包</div> -->
              <div class="chatRedTimeRight">
                <span>12:30</span>
              </div>
            </div>
          </div>

          <!--右-->
          <div class="talkLeftCon clearfix">
            <div class="headPic float-right">
              <img src="../assets/img/pic1.jpg" />
            </div>
            <div class="chat float-right">
              <div class="talkRedContent row">
                <div class="talkRedEnvel col-4">
                  <img src="../assets/img/envelopes.png" />
                </div>
                <div class="talkRedName col-8">
                  <div class="nameTop"></div>
                  <div class="namemsg">這是備註文字</div>
                </div>
              </div>
              <!-- <div class="talkRedBottom talkRedBottomFontG">紅包已發送</div> -->
              <div class="chatRedTimeLeft">
                <span>12:30</span>
              </div>
            </div>
          </div>
          <!--右-->
          <div class="talkLeftCon clearfix">
            <div class="headPic float-right">
              <img src="../assets/img/pic1.jpg" />
            </div>
            <div class="chat float-right">
              <div class="talkRedContent row">
                <div class="talkRedEnvel col-4">
                  <img src="../assets/img/envelopesOpen.png" />
                </div>
                <div class="talkRedName col-8">
                  <div class="nameTop"></div>
                  <div class="namemsg">這是備註文字</div>
                </div>
              </div>
              <!-- <div class="talkRedBottom talkRedBottomFontG">紅包已被領取</div> -->
              <div class="chatRedTimeLeft">
                <span>12:30</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="talkMenu clearfix" v-if="talkMenu" v-show="isShow">
        <div class="talkMenuItem float-left">
          <div class="menuBtnBg">
            <img src="../assets/img/camera.png" />
          </div>
          <div class="menuFont">相機</div>
        </div>
        <div class="talkMenuItem float-left">
          <div class="menuBtnBg">
            <img src="../assets/img/photo.png" />
          </div>
          <div class="menuFont">相片</div>
        </div>
        <div class="talkMenuItem float-left">
          <div class="menuBtnBg">
            <img src="../assets/img/microphone.png" />
          </div>
          <div class="menuFont">聲音</div>
        </div>
        <div class="talkMenuItem float-left">
          <div class="menuBtnBg">
            <img src="../assets/img/envelope.png" />
          </div>
          <div class="menuFont">紅包</div>
        </div>
      </div>
      <div class="talkBottom clearfix">
        <div class="bottomLeftRight float-left">
          <img :src="menuBtnSrc" @click="talkMenuClick()" />
        </div>
        <div class="bottomInput float-left">
          <input type="text" class />
        </div>
        <div class="bottomLeftRight float-left">
          <img src="../assets/img/path.png" />
        </div>
      </div>
    </div>

    <div
      class="modal"
      id="exampleModal"
      tabindex="-1"
      role="dialog"
      aria-labelledby="exampleModalLabel"
      aria-hidden="true"
    >
      <div class="modal-dialog" role="document">
        <div class="modal-content mcontentBg">
          <div class="modal-header modalHeaderBg mheaderBorder mheaderFont">
            <div class="headerBg">
              提示
              <button
                type="button"
                class="close headerBtn"
                data-dismiss="modal"
                aria-label="Close"
              >
                <img src="../assets/img/closeBtn.png" />
              </button>
            </div>
          </div>
          <div class="modal-body mbodyFont">是否刪除對話紀錄</div>
          <div class="modal-footer clearfix">
            <div class="modalBtn modalBtnGreen modalBtnFont float-left">確認</div>
            <div class="modalBtn modalBtnRed modalBtnFont float-left" data-dismiss="modal">取消</div>
          </div>
        </div>
      </div>
    </div>
    <!--  -->
    <div class="mask redEvelModal" v-if="redEnvelopesModal">
      <div class="redEvelCon">
        <img src="../assets/img/redEnvelopes.png" />
        <div class="redClose" @click="closeredModal()">
          <img src="../assets/img/redClose.png" />
        </div>
        <div class="redCon">
          <div class="redPic">
            <img src="../assets/img/pic1.jpg" />
          </div>
          <div class="redName">Aaron</div>
          <div class="redTalk">發紅包給您</div>
        </div>
        <div class="redOpenFont" @click="OpenredEnvModal()">拆紅包</div>
      </div>
    </div>

    <div class="mask redEvelModal" v-if="redEnvOpenModal">
      <div class="redEvelCon">
        <img src="../assets/img/BenvelopesOpen.png" />
        <div class="redClose" @click="closeOpnModal()">
          <img src="../assets/img/redClose.png" />
        </div>
        <div class="redCon">
          <div class="redPic">
            <img src="../assets/img/pic1.jpg" />
          </div>
          <div class="redOpenCon">
            我一吹口哨
            <br />他就不會咬人了
          </div>
          <div class="redOpenPoint">150.00</div>
        </div>
        <div class="redOpenDetails">
          查看領取詳情
          <img src="../assets/img/sright.png" />
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
      talkMenu: false,
      deleteModal: false,
      menuBtnSrc: require('../assets/img/bottomMenu.png'),
      redEnvelopesModal: false,
      redEnvOpenModal: false
    }
  },
  methods: {
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
    redEnvelopes() {
      this.redEnvelopesModal = true
    },
    closeredModal() {
      this.redEnvelopesModal = false
    },
    OpenredEnvModal() {
      this.redEnvelopesModal = false
      this.redEnvOpenModal = true
    },
    closeOpnModal() {
      this.redEnvOpenModal = false
    }
  }
}
</script>
<style scoped>
</style>

