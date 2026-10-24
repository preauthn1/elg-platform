<template>
  <div class="box">
    <!-- <img src="../assets/img/search_btn.png" class="search-btn" /> -->
    <router-link
      v-for="category in categories"
      :key="category.id"
      class="item"
      :class="{ selected: selectedId === category.id }"
      :to="category.path + query"
    >
      <img
        class="img"
        :src="
          selectedId === category.id ? category.selectedImage : category.image
        "
      />
      <div class="name">{{ category.name }}</div>
    </router-link>
  </div>
</template>

<script>
export default {
  computed: {
    query() {
      const query = this.$route.query;
      return Object.keys(query).length === 0
        ? ""
        : "?" +
            Object.keys(query)
              .map((key) => `${key}=${query[key]}`)
              .join("&");
    },
    selectedId() {
      if (this.$route.path === "/") {
        return 102;
      }
      if (this.$route.path === "/favorite") {
        return 101;
      }
      const gameTypeId = this.$route.params.gameTypeId;
      if (gameTypeId !== undefined) {
        return +gameTypeId;
      }
      return 0;
    },
  },
  data() {
    return {
      categories: [
        {
          id: 101,
          name: "我的最愛",
          image: require("../assets/img/categories/favorite.png"),
          selectedImage: require("../assets/img/categories/favorite_selected.png"),
          path: "/favorite",
        },
        {
          id: 102,
          name: "全部遊戲",
          image: require("../assets/img/categories/all.png"),
          selectedImage: require("../assets/img/categories/all_selected.png"),
          path: "/",
        },
        {
          id: 2,
          name: "老虎機",
          image: require("../assets/img/categories/slot.png"),
          selectedImage: require("../assets/img/categories/slot_selected.png"),
          path: "/game-type/2",
        },
        {
          id: 3,
          name: "撲克",
          image: require("../assets/img/categories/poker.png"),
          selectedImage: require("../assets/img/categories/poker_selected.png"),
          path: "/game-type/3",
        },
        {
          id: 5,
          name: "打魚遊戲",
          image: require("../assets/img/categories/fishing.png"),
          selectedImage: require("../assets/img/categories/fishing_selected.png"),
          path: "/game-type/5",
        },
        {
          id: 6,
          name: "鬥雞",
          image: require("../assets/img/categories/cockfight.png"),
          selectedImage: require("../assets/img/categories/cockfight_selected.png"),
          path: "/game-type/6",
        },
        {
          id: 15,
          name: "機台",
          image: require("../assets/img/categories/arcade.png"),
          selectedImage: require("../assets/img/categories/arcade_selected.png"),
          path: "/game-type/15",
        },
        {
          id: 21,
          name: "跑燈機",
          image: require("../assets/img/categories/run_light.png"),
          selectedImage: require("../assets/img/categories/run_light_selected.png"),
          path: "/game-type/21",
        },
      ],
    };
  },
};
</script>

<style scoped>
.box {
  background-color: #1b3254;
  padding: 8px;
  display: flex;
  align-items: center;
  overflow-x: auto;
}

.search-btn {
  width: 40px;
  height: 40px;
  cursor: pointer;
  margin-right: 16px;
}
.item {
  display: flex;
  align-items: center;
  padding: 8px 0;
  cursor: pointer;
  margin-right: 30px;
  text-decoration: none;
}
.item .img {
  height: 20px;
  margin-right: 4px;
  object-fit: contain;
}

.item .name {
  color: #b9c6e6;
  white-space: nowrap;
}

.item.selected .name {
  color: #fff;
}
</style>
