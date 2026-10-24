<template>
  <div>
    <!-- <div style="color:red;">
      TOKEN:[REDACTED]
      <br />
      CUSTOMER:{{this.$route.params}}
    </div>-->
  </div>
</template>
<script crossorigin="anonymous">
export default {
  data() {
    return {
      token: [REDACTED],
      customerID: this.$route.params.customerID
    }
  },
  mounted() {
    this.getCustomer()
  },
  methods: {
    getCustomer() {
      setTimeout(() => {
        // console.log('token', this.token)
        // console.log('customerID', this.customerID)
        sessionStorage.setItem('user_token', this.token)
        sessionStorage.setItem('user_id', this.customerID)
        // console.log('weblogin id', this.token, this.customerID)
        // this.$router.push('/index')
        window.location = '/index'
        // 重刷一次，防止cors不過
        // if (location.href.indexOf('#') == -1) {
        //   location.href = location.href + '#'
        //   location.reload()
        // } else {
        //   console.log('login reload')
        // }
      }, 1000)
    }
  }
}
</script>