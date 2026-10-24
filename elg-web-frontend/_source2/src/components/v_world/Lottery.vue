
<template>
  <div class="Lottery">
    <div class="Events_bar">
      <EventsHeader></EventsHeader>
    </div>
    <div class="LotteryAll">
      <img :src="lotteryBg" class="LotteryBgPic" />
      <div class="LotteryTitle">{{ get_Prize.name }}</div>
      <div class="LotteryGo">
        <v-lottery :data="awardsData" @onstart="startRotation" @onstop="stopRotation"></v-lottery>
      </div>
    </div>
    <!-- <div class="mask" v-if="getPrize">
      <div class="modalBlueAll">
        <span class="eventFontTitle">{{ $t("itemname") }}:</span>
        <p>{{ get_Prize.prize.name }}</p>
        <div class="greenBtn modalBtn" style="margin:auto;" @click="close()">{{ $t("confirm") }}</div>
      </div>
    </div>-->
    <div v-if="getPrize" class="mask depositvisible">
      <div class="modalTitleAll">
        <div class="modalTitle">
          <img src="../../assets/img/v_world/modalTitle.png" class="TitlePic" />
          <div class="modalTitleAll">
            <div class="contentTitle">{{ $t("itemname") }}</div>
          </div>
        </div>
        <div class="modalContentAll">
          <div class="nomoneyFont" style="padding-top:20px;">{{ get_Prize.prize.name }}</div>
          <div class="imgfontGreen im" @click="close()">
            <span>{{ $t("confirm") }}</span>
          </div>
        </div>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>


<script>
import { mapGetters } from 'vuex'
import maskModal from './Modal'
import EventsHeader from '@/components/v_world/Events_header'
import { fail } from 'assert'

export default {
  components: {
    EventsHeader,
    maskModal
  },
  computed: {
    ...mapGetters(['get_Prize', 'get_Prizes'])
  },
  data() {
    return {
      isLottery: false,
      getPrize: false,
      lotteryBg:
        'https://example.invalid/cdn-betmonsters-com-cdn-bucket/156153486617.png', // 背景圖更換
      awardsData: {
        data: [
          // title: 獎品名稱  color: 區塊颜色(奇數色號:#FDB451偶數色號:#D83B3D)
          //   {
          //     id: 1,
          //     title: '獎品一',
          //     color: '#FDB451'
          //   },
          //   {
          //     id: 2,
          //     title: '獎品二',
          //     color: '#D83B3D'
          //   },
        ],
        target: '',
        bgColor: '#D83B3D', // 外環背景色
        goImage: require('../../assets/img/v_world/go2.gif') //指針圖片
      }
    }
  },
  mounted() {
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
    close() {
      this.getPrize = false
    },
    startRotation() {
      if (!this.isLottery) {
        // 所獲獎品ID
        this.awardsData.target = this.get_Prize.prize.id
        //設定獎項call api setPrize
        this.$store.dispatch(
          'action_SetPrize',
          this.get_Prize.prize.prize_member
        )
        this.isLottery = true
      }
    },
    stopRotation() {
      var lotterysound = document.getElementById('yisell')
      var lotteryGoPic = document.getElementById('lotteryGoPic')
      lotterysound.pause()
      this.getPrize = true
      //   console.log(lotteryGoPic)
      //   console.log('stopRotation')
    }
  },

  created() {
    this.awardsData.data = this.get_Prizes
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->

