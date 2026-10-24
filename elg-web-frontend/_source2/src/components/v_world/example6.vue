<template>
  <div class="swiper">
    <!-- swiper -->
    <swiper :options="swiperOption">
      <swiper-slide v-for="(item, index) in imgs" :key="index">
        <img :src="item.img" class="swiperPic" />
      </swiper-slide>

      <!-- 分頁點 -->
      <div class="swiper-pagination" slot="pagination"></div>
      <!-- 上一頁 -->
      <!-- <div class="swiper-button-prev" slot="button-prev"></div> -->
      <!-- 下一頁 -->
      <!-- <div class="swiper-button-next" slot="button-next"></div> -->
    </swiper>
  </div>
</template>

<script>
import 'swiper/dist/css/swiper.css'
import { swiper, swiperSlide } from 'vue-awesome-swiper'
export default {
  data() {
    return {
      swiperOption: {
        slidesPerView: 3, //一行顯示3個
        slidesPerColumn: 3, //顯示3行
        spaceBetween: 10, //在slide之間設置距離(px)
        slidesPerColumnFill: 'row', //多行佈局中以什麼形式填充列（column）和行（row）。類型：string，默認：column。
        grabCursor: true, // 游標為手掌
        slidesOffsetBefore: 20, //設定slide與左邊框的預設偏移量（單位px）
        slidesOffsetAfter: 20, //設定slide與右邊框的預設偏移量（單位px）
        // effect: 'cube', // 效果
        // cubeEffect: {
        //   shadow: true, // 顯示陰影
        //   slideShadows: true, // 當滑動時，則顯示動陰影
        //   shadowOffset: 20, // 陰影偏移
        //   shadowScale: 0.94 // 陰影比例
        // },
        pagination: {
          el: '.swiper-pagination', //分頁點
          clickable: true
        }
        // navigation: {
        //   nextEl: '.swiper-button-next',
        //   prevEl: '.swiper-button-prev'
        // }
      },

      imgs: [
        { img: '../../../static/img/v_world/game1.png' },
        { img: '../../../static/img/v_world/game2.png' },
        { img: '../../../static/img/v_world/game3.png' },
        { img: '../../../static/img/v_world/game4.png' },
        { img: '../../../static/img/v_world/game5.png' },
        { img: '../../../static/img/v_world/game1.png' },
        { img: '../../../static/img/v_world/game2.png' },
        { img: '../../../static/img/v_world/game3.png' },
        { img: '../../../static/img/v_world/game4.png' },
        { img: '../../../static/img/v_world/game5.png' }
      ]
    }
  },
  components: {
    swiper,
    swiperSlide
  },
  mounted() {
    //手機方向
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'block')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
    // setInterval(() => {
    //   console.log('simulate async data')
    //   if (this.swiperSlides.length < 10) {
    //     this.swiperSlides.push(this.swiperSlides.length + 1)
    //   }
    // }, 3000)
  }
}
</script>
<style scoped>
.swiper {
  background-color: #fff;
}
.swiper-container {
  height: auto !important;
  margin-left: auto;
  margin-right: auto;
}
.swiperPic {
  width: 100%;
  max-height: 100%;
}
.swiper-slide {
  height: 100px;
  width: 60%;
}
.swiper-slide:nth-child(2n) {
  width: 40%;
}
.swiper-slide:nth-child(3n) {
  width: 20%;
}
</style>