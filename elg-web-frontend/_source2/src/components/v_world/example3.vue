<template></template>

<script>
import axios from 'axios'

export default {
  data() {
    return {}
  },
  components: {},
  mounted() {
    if (window.orientation == 0 || window.orientation == 180) {
      $('.directionMask').css('display', 'none')
    }
    window.onorientationchange = function() {
      if (window.orientation == 90 || window.orientation == -90) {
        $('.directionMask').css('display', 'none')
      } else {
        $('.directionMask').css('display', 'none')
      }
    }
  }
}
</script>
<style>
* {
  margin: 0;
  padding: 0;
}
li {
  list-style: none;
}
.m-slider {
  width: 600px;
  margin: 50px 20px;
  overflow: hidden;
}
.m-slider .cnt {
  position: relative;
  left: 0;
  width: 3000px;
}
.m-slider .cnt li {
  float: left;
  width: 600px;
}
.m-slider .cnt img {
  display: block;
  width: 100%;
  height: 450px;
}
.m-slider .cnt p {
  margin: 20px 0;
}
.m-slider .icons {
  text-align: center;
  color: #000;
}
.m-slider .icons span {
  margin: 0 5px;
}
.m-slider .icons .curr {
  color: red;
}
.f-anim {
  -webkit-transition: left 0.2s linear;
}
</style>