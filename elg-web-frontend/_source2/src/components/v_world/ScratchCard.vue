
<template>
  <div class="widthHeight BgContent BgAll" ref="ScratchCard">
    <EventsHeader></EventsHeader>
    <!-- <div class="cardEventHeader">
      <EventsHeader></EventsHeader>
    </div>-->
    <!-- <div class="dowebok">
      <img id="robot" src="img/robot.jpg" />
      <img id="redux" src="http://minimal.be/lab/jQuery.eraser/img/robot_redux.png" />
      <div id="progress" style="color:#000;">0%</div>
    </div>-->
    <div class="ScratchCardAll">
      <div class="scratchCardMask" @touchstart="maskClick()" id="maskPic">
        <img src="../../assets/img/v_world/hand.gif" class="maskPic" />
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
</template>


<script>
import { mapGetters } from 'vuex'
import maskModal from './Modal'
import EventsHeader from '@/components/v_world/Events_header'

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
      resultSrc: require('../../assets/img/v_world/cardNew.png'),
      coverFont: '',
      coverTitle: '',
      is_set: false,
      scratchCardMask: true,
      cardSound: true,
      isplayed: true
    }
  },
  mounted() {
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
      //刮開一部份就讓他整張刮開
      this.$refs.vueEraser.clear()
      //   console.log('complete')
      //   var cardSound = document.getElementById('card')
      //   cardSound.pause()
    },
    progressFunction(ratio) {
      //一刮就設定他已兌獎
      if (!this.is_set) {
        this.is_set = true
        this.coverFont = this.get_Prize.prize.name
        // this.coverTitle = this.get_Prize.name
        this.$store.dispatch(
          'action_SetPrize',
          this.get_Prize.prize.prize_member
        )
      }
    },
    // reset() {
    //     this.$refs.vueEraser.reset()
    // },
    // clear() {
    //     this.$refs.vueEraser.clear()
    // }
    getBG() {
      this.coverSrc = this.get_Prize.background
    }
  },
  created() {
    this.getBG()
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->

