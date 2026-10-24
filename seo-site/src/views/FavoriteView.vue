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
      const favoriteGameIds = this.$store.state.favoriteGameIds;
      const games = this.$store.getters.games.filter((game) =>
        favoriteGameIds.map(String).includes(game.id.toString())
      );

      if (this.filterVendorIds.length === 0) {
        return games;
      }

      return games.filter((game) =>
        this.filterVendorIds.map(String).includes(game.vendor_id.toString())
      );
    },
  },
};
</script>
