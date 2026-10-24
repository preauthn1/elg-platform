
<template>
  <div style="color:red;">{{this.$route.params.deviceId,}}</div>
</template>
<script>
export default {
  mounted() {
    sessionStorage.setItem('device', this.$route.params.deviceId)
    // sessionStorage.setItem('device', '156421')
    this.$router.push('/Login')
  }
}
</script>