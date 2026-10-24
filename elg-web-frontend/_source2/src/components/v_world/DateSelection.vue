<template>
  <div class="widthHeight BgContent BgAll">
    <LoginHeader></LoginHeader>
    <div class="AllContent">
      <div class="DateSelectionCon">
        <span>{{ $t("searchForRecordAndDetailsFor30Days") }}</span>
        <div class="DateFont">{{ $t("startDate") }}{{startDate}}</div>
        <vue-datepicker-local v-model="startDate" />
        <div class="DateFont">{{ $t("endDate") }}{{endDate}}</div>
        <vue-datepicker-local v-model="endDate" />
      </div>
      <div @click="playSound();search()" class="imgfontAll">
        <span>{{ $t("search") }}</span>
      </div>
    </div>
    <!-- 彈窗 -->
    <maskModal></maskModal>
  </div>
</template>

<script>
import VueDatepickerLocal from 'vue-datepicker-local'
import LoginHeader from '@/components/v_world/Login_header'
import maskModal from './Modal'
export default {
  components: {
    VueDatepickerLocal,
    LoginHeader,
    maskModal
  },
  data() {
    return {
      startDate: '',
      endDate: new Date()
    }
  },
  mounted() {
    //手機方向
    $('.directionMask').css('display', 'none')
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
  },
  methods: {
    playSound() {
      var sound = document.getElementById('audio')
      sound.play()
    },
    defaultStart() {
      let dt = this.endDate
      this.startDate = new Date(dt.getTime() - 30 * 24 * 60 * 60 * 1000)
    },
    search() {
      window.sessionStorage.setItem('isSearchRecord_lock', '1')
      function append_zero(obj) {
        if (obj < 10 && obj.length < 2) return '0' + obj
        else return obj
      }
      /// 結束 //
      let dt = new Date(this.endDate)
      let year = dt.getFullYear()
      let month = append_zero((dt.getMonth() + 1).toString())
      let date = append_zero(dt.getDate().toString())
      let hours = append_zero(dt.getHours().toString())
      let minute = append_zero(dt.getMinutes().toString())
      let second = append_zero(dt.getSeconds().toString())
      let endTime =
        year +
        '-' +
        month +
        '-' +
        date +
        ' ' +
        hours +
        ':' +
        minute +
        ':' +
        second
      /// 開始 //
      let start = new Date(this.startDate)
      let sYear = start.getFullYear()
      let sMonth = append_zero((start.getMonth() + 1).toString())
      let sDate = append_zero(start.getDate().toString())
      let sHours = append_zero(start.getHours().toString())
      let sMinute = append_zero(start.getMinutes().toString())
      let sSecond = append_zero(start.getSeconds().toString())
      let startTime =
        sYear +
        '-' +
        sMonth +
        '-' +
        sDate +
        ' ' +
        sHours +
        ':' +
        sMinute +
        ':' +
        sSecond

      let range = {}
      range['start_time'] = startTime
      range['end_time'] = endTime
      this.$store.dispatch('actionRecord_point', range)
    }
  },
  created() {
    this.defaultStart()
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
