<template>
  <div>
    1111
    <!-- <ul v-for="(item, index) in imgs">
      <li>
        <a href="#">
          <img :src="item.img" />
        </a>
      </li>
    </ul>-->

    <!-- <div class="slickDiv float-left" v-for="(item, index) in imgs">
      <img :src="item.img" />
    </div>-->
  </div>
</template>

<script>
// import '../../../static/slick/slick.js'
import '../../assets/js/slick.js'

export default {
  data() {
    return {
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
  components: {},
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

    $('.slick').slick({
      dots: true, //顯示輪播圖片會顯示圓圈
      infinite: true, //重覆輪播
      slidesToShow: 3, //輪播顯示個數
      slidesToScroll: 3, //輪播捲動個數
      autoplay: false, //autoplay : 自動播放
      responsive: [
        {
          breakpoint: 1000,
          settings: {
            slidesToShow: 2,
            slidesToScroll: 2,
            arrows: false
          }
        },
        {
          breakpoint: 480,
          settings: {
            slidesToShow: 1,
            slidesToScroll: 1,
            arrows: false
          }
        }
      ]
    })
  }
}
</script>
<style>
.slick-prev {
  left: -50px;
}
.slick-prev:before,
.slick-next:before {
  font-size: 50px;
  color: #8d0404;
}
</style>