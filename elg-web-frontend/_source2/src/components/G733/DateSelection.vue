<template>
  <div id="G733">
    <LoginHeader></LoginHeader>
    <!-- LoginMain -->
    <div class="single_block">
      <div class="LoginMain">
        <img src="../../assets/img/G733/bg.png" alt class="loginBg" />
        <div class="mainNoB zindex2">
          <div class="pcmainBg">
            <div class="mainLogin"></div>
            <div class="loginInfo">
              <div class="dateSelect">
                <div class="DategaryFont">{{ $t("searchForRecordAndDetailsFor30Days") }}</div>
                <div class="DateFont">{{ $t("startDate") }}</div>
                <div>{{ startDate }}</div>
                <vue-datepicker-local v-model="startDate" />
                <div class="DateFont">{{ $t("endDate") }}</div>
                <div>{{ endDate }}</div>
                <vue-datepicker-local v-model="endDate" />
              </div>
            </div>

            <div class="silverBorder btnBorder_all">
              <button
                type="submit"
                @click="playSound();search();"
                class="redBtn btn_all"
              >{{ $t("search") }}</button>
            </div>
          </div>
          <!-- 彈窗 -->
          <maskModal></maskModal>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import LoginHeader from '@/components/G733/Login_header'
import maskModal from './Modal'
import VueDatepickerLocal from 'vue-datepicker-local'
export default {
  components: {
    LoginHeader,
    VueDatepickerLocal,
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
      // 開始時間
      let dt = this.endDate
      this.startDate = new Date(dt.getTime() - 30 * 24 * 60 * 60 * 1000)
    },
    // 搜尋
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
