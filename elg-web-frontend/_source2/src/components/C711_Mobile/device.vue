
<template>
  <!-- 帶device id 轉跳 -->
  <div style="color:red;">
    <!-- {{this.$route.params.deviceId,}} -->
  </div>
</template>
<script>
export default {
  // 帶DEVICE = APP進入
  mounted() {
    sessionStorage.setItem('device', this.$route.params.deviceId)
    // sessionStorage.setItem('device', '156421')
    this.$router.push('/Login')
  }
}
</script>