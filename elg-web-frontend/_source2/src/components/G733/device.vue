
<template>
  <!-- 帶device id (app)轉跳 -->
  <div style="color:red;">
    <!-- {{this.$route.params.deviceId,}} -->
  </div>
</template>
<script>
export default {
  mounted() {
    sessionStorage.setItem('device', this.$route.params.deviceId)
    this.$router.push('/Login')
  }
}
</script>