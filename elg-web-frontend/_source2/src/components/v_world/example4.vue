<template>
  <div id="div1">
    <div id="div2">
      <ul v-for="(item, index) in imgs">
        <li>
          <a href="#">
            <img :src="item.img" />
          </a>
        </li>
      </ul>
    </div>
  </div>
</template>
       
<script>
window.onload = function() {
  var odiv2 = document.getElementById('div2')
  var ali = odiv2.getElementsByTagName('li')
  var aspeed = -5
  var timer = null
  odiv2.innerHTML += odiv2.innerHTML
  odiv2.style.width = ali[0].offsetWidth * ali.length + 'px'
  odiv2.onmouseover = function() {
    clearInterval(timer)
  }
  function a() {
    timer = setInterval(function() {
      odiv2.style.left = odiv2.offsetLeft + aspeed + 'px'
      if (odiv2.offsetLeft < -odiv2.offsetWidth / 2) {
        odiv2.style.left = '0px'
      }
    }, 100)
  }
  odiv2.onmouseout = a
  a()
}
export default {
  components: { Slick },

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
  }
}
</script>

<style>
#div1 {
  position: relative;
  width: 800px;
  height: 200px;
  overflow: hidden;
}
#div2 {
  position: absolute;
}
li {
  float: left;
  list-style-type: none;
  padding: 3px;
}
img {
  border: none;
}
#div2 li a:hover {
  top: -10px;
}
a {
  position: relative;
}
</style>

