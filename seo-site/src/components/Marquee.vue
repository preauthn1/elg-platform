<template>
  <div class="marquee">
    <img class="marquee-img" src="../assets/img/marquee.png" />
    <MarqueeText :duration="30">
      <span v-for="i in 10" :key="i" class="mx-3 content">
        has just won ${{ i }} in Speed Baccarat A
      </span>
    </MarqueeText>
  </div>
</template>

<script>
import MarqueeText from "vue-marquee-text-component";

export default {
  components: {
    MarqueeText,
  },
};
</script>

<style scoped>
.marquee {
  background: #1c84fa;
  display: flex;
  align-items: center;
  height: 24px;
}

.marquee-img {
  height: 16px;
  width: 16px;
  margin: 0 8px;
}
.content {
  font-size: 12px;
}
</style>
