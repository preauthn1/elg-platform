<template>
  <div id="C711Mobile">
    <div class="pageBg fontFamily widthHeight widthHeightvh" ref="ScratchCard">
      <EventsHeader></EventsHeader>

      <div class="ScratchCardAll">
        <div class="scratchCardMask" @touchstart="maskClick()" @click="maskClick()" id="maskPic">
          <img src="../assets/img/C711_Mobile/hand.gif" class="maskPic" />
        </div>

        <div class="scratchCardGIF" style="display:none;">
          <img src="../assets/img/C711_Mobile/coin.gif" />
        </div>

        <vue-eraser
          ref="vueEraser"
          :size="50"
          :complete-ratio="0.35"
          :cover-src="coverSrc"
          :result-src="resultSrc"
          :coverFont="coverFont"
          :coverTitle="coverTitle"
          :complete-function="completeFunction"
          :progress-function="progressFunction"
        ></vue-eraser>
      </div>
      <!-- 彈窗 -->
      <maskModal></maskModal>
    </div>
  </div>
</template>

<script>
import { mapGetters } from 'vuex'
import maskModal from './C711_Mobile/Modal'
import EventsHeader from './C711_Mobile/Events_header'

export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_Prize'])
  },
  data() {
    return {
      coverSrc: 'http://minimal.be/lab/jQuery.eraser/img/robot_redux.png',
      resultSrc: require('../assets/img/C711_Mobile/cardNew2.png'),
      //   coverFont: '',
      coverFont: '',
      coverTitle: '',
      is_set: false,
      scratchCardMask: true,
      cardSound: true,
      isplayed: true,
      prizePoint: ''
    }
  },
  mounted() {
    var bannerOpen = sessionStorage.getItem('bannerOpen')
    if (bannerOpen == 1) {
      $('#lotteryback').css('display', 'none')
    }
    $('.footer_block').css('display', 'none')
    var cardSound = document.getElementById('card')
    $(document).ready(function() {
      cardSound.play()
    })
    $('.directionMask').css('display', 'none')
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    //橫向禁止
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
  },
  methods: {
    maskClick() {
      $('#maskPic').hide()
    },
    completeFunction(ratio) {
      //   刮開一部份就讓他整張刮開

      this.prizePoint = this.get_Prize.prize.point
      // 活動假資料
      //   this.prizePoint = 100

      if (this.prizePoint != 0) {
        $('.scratchCardGIF').css('display', 'block')
        setTimeout(() => {
          $('.scratchCardGIF').css('display', 'none')
        }, 4000)
      } else {
        $('.scratchCardGIF').css('display', 'none')
      }
      this.$refs.vueEraser.clear()

      //   var cardSound = document.getElementById('card')
      //   cardSound.pause()
    },
    progressFunction(ratio) {
      //一刮就設定他已兌獎
      if (!this.is_set) {
        this.is_set = true
        this.coverFont = this.get_Prize.prize.name
        // 活動假資料
        // this.coverFont = 'v-point 300 點'

        this.$store.dispatch(
          'action_SetPrize',
          this.get_Prize.prize.prize_member
        )
      }
    },

    getBG() {
      this.coverSrc = this.get_Prize.background
      //   活動假資料
      //   this.coverSrc ='https://example.invalid/cdn-betmonsters-com-cdn-bucket/156765173322.png'
    }
  },
  created() {
    this.getBG()
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
