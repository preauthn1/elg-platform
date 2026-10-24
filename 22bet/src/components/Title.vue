<template>
  <div class="title">Registration</div>
  <div class="content">
    <img class="title-icon" src="../assets/title_icon.png" />
    <div class="message mt-3">
      {{ message }}
    </div>
  </div>
</template>

<script>
export default {
  computed: {
    message() {
      switch (location.host) {
        case "example.invalid":
          return "REGISTER AND GET A BONUS OF UP TO 4500 PHP FOR SPORTS BETTING OR UP TO 300 USD FOR CASINO GAMES RIGHT NOW!";
        case "example.invalid":
          return "REGISTER AND GET A BONUS OF UP TO RM 188 FOR SPORTS BETTING OR UP TO RM 888 FOR CASINO GAMES RIGHT NOW!";
        default:
          return "REGISTER AND GET A BONUS OF UP TO 4500 PHP FOR SPORTS BETTING OR UP TO 300 USD FOR CASINO GAMES RIGHT NOW!";
      }
    },
  },
};
</script>

<style lang="scss" scoped>
.title {
  text-align: center;
  font-size: 22px;
  font-weight: 700;
  color: #234d54;
  text-transform: uppercase;
  margin: 16px 0;
}
.content {
  background-color: #0a636b;
  text-align: center;
  padding: 1rem 2rem;
}
.title-icon {
  height: 34px;
  width: 139px;
  object-fit: contain;
}
.message {
  color: #fff;
  text-transform: uppercase;
  border: 1px solid #fff;
  padding: 1rem 2.5rem;
  font-weight: bold;
  font-size: 20px;
  line-height: 23px;
}
</style>
