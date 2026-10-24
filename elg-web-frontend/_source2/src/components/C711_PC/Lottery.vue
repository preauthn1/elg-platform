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

    <div class="mask" style="z-index:9997; background-color: rgba(0, 0, 0, 0.5);" v-if="getPrize">
      <img
        src="../../assets/img/firework_0.gif"
        class="firework"
        v-if="firework"
        style="z-index:9998;"
      />
      <div class="lottery_Modal" style="z-index:9999; transform: translateY(-30%)">
        <div class="PopupsTitle">{{ $t("itemname") }}</div>
        <div class="PopupsConAll">
          <div class="PopupsCon popupsConFont">
            <div>{{ get_Prize.prize.name }}</div>
            <!-- 活動假資料 -->
            <!-- <div>v-point 300點</div> -->
          </div>
          <div class="PopupsBtn" @click="close()">
            <div class="btn-lottery">
              <button type="submit" @click="close()" style="padding:9px auto; ">{{ $t("confirm") }}</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { mapGetters } from 'vuex'

import EventsHeader from '@/components/C711_PC/Events_header'
import { fail } from 'assert'
import '../../assets/scss/C711_PC/css.css'
import '../../assets/scss/C711_PC/style.css'
export default {
  components: {
    EventsHeader
  },
  computed: {
    ...mapGetters(['get_Prize', 'get_Prizes'])
  },
  data() {
    return {
      isLottery: false,
      getPrize: false,
      getPrizeFire: false,
      firework: true,
      lotteryBg:
        'https://example.invalid/cdn-betmonsters-com-cdn-bucket/158097838051.jpeg', // 背景圖更換
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
        bgColor: '' // 外環背景色
        // goImage: require(".") //lottery2裡面指針圖片
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
      var firesound = document.getElementById('fire')
      firesound.pause()
    },
    startRotation() {
      if (!this.isLottery) {
        // 所獲獎品ID
        this.awardsData.target = this.get_Prize.prize.id
        // 活動假資料
        // this.awardsData.target = 392;
        //設定獎項call api setPrize
        this.$store.dispatch(
          'action_SetPrize',
          this.get_Prize.prize.prize_member
        )
        // 活動假資料
        // this.$store.dispatch("action_SetPrize", 1);
        if (this.get_Prize.prize.point == 0) {
          this.firework = false
        }
        this.isLottery = true
      }
    },
    stopRotation() {
      var lotterysound = document.getElementById('yisell')
      var lotteryGoPic = document.getElementById('lotteryGoPic')
      lotterysound.pause()
      this.getPrize = true
      var firesound = document.getElementById('fire')
      firesound.play()
    }
  },

  created() {
    this.awardsData.data = this.get_Prizes
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
