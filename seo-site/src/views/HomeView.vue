<template>
  <div>
    <Header />
    <Marquee class="mt-3" />
    <Banner />
    <Notification />
    <GameTypeBar class="mb-3" />
    <SortingAndVendor class="px-3 mb-3" />
    <GameList :games="games" class="px-3 mb-3" :sort-id="$route.query.sort" />
  </div>
</template>

<script>
import Header from "../components/Header.vue";
import Marquee from "../components/Marquee.vue";
import Banner from "../components/Banner.vue";
import Notification from "../components/Notification.vue";
import GameTypeBar from "../components/GameTypeBar.vue";
import SortingAndVendor from "../components/SortingAndVendor.vue";
import GameList from "../components/GameList.vue";
import vendorMixin from "../mixins/vendorMixin";
export default {
  mixins: [vendorMixin],
  metaInfo: {
    title: "target",
  },
  components: {
    Header,
    Marquee,
    Banner,
    Notification,
    GameTypeBar,
    SortingAndVendor,
    GameList,
  },
  computed: {
    games() {
      const allGames = this.$store.getters.games;
      if (this.filterVendorIds.length === 0) {
        return allGames;
      }
      return allGames.filter((game) =>
        this.filterVendorIds.map(String).includes(game.vendor_id.toString())
      );
    },
  },
};
</script>
