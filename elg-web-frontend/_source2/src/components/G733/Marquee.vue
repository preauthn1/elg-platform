<template>
  <div class="marqueeRun" v-if=" EventMode == 1">
    <div class="marquee" @mouseover="stop" @mouseout="lang">{{mag}}</div>
  </div>
</template>
<script>
export default {
  data() {
    return {
      EventMode: sessionStorage.getItem('eventMode'),
      mag: this.$t('youAreCurrentlyInTheEventModePC'),
      //   mag: '您目前正在活動模式中，詳情可至活動列表中查看....',
      intervalId: null //data 定義一個定時器ID
    }
  },
  mounted() {
    if (this.EventMode == 1) {
      setTimeout(() => {
        this.lang()
      }, 2000)
    }
  },
  methods: {
    // 鎖分模式跑馬燈

    lang() {
      if (this.intervalId != null) return
      this.intervalId = setInterval(() => {
        //得到第一個字元
        var start = this.mag.substring(0, 1)
        //得到最後一個字元
        var end = this.mag.substring(1)
        //後面與前面字元連接
        this.mag = end + start
      }, 400)
    },
    //停止計時器
    stop() {
      clearInterval(this.intervalId)
      //當我清除定時器之後，重新讓intervalId為null
      this.intervalId = null
    }
  }
}
</script>