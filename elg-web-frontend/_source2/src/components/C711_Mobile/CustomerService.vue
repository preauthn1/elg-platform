<template>
  <!-- 這頁目前無用 -->
  <div>{{get_CustomerData}}</div>
</template>

<script>
import Global from '@/Global'
import { mapGetters, mapMutations } from 'vuex'
import axios from 'axios'
export default {
  name: 'App',
  data() {
    return {}
  },
  computed: {
    ...mapGetters(['get_CustomerData'])
  },
  mounted() {
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
    this.getCustomer()
  },
  methods: {
    getCustomer() {
      setTimeout(() => {}, 1000)
    }
  },

  created() {}
}
</script>
