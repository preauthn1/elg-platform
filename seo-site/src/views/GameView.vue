<template>
  <div>
    <div class="nav">
      <div class="left">
        <img
          src="../assets/img/back.svg"
          class="back-img"
          @click="$router.push('/')"
        />
      </div>
      <div class="center">遊戲介紹</div>
      <div class="right"></div>
    </div>
    <div class="row px-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="ratio ratio-1x1">
          <img :src="gameImg" :alt="name" class="game-img w-100" />
        </div>
        <a
          class="btn btn-primary start-btn w-100 mt-3"
          href="https://example.invalid/"
        >
          開始遊戲
        </a>
      </div>
      <div class="col-6">
        <div class="d-flex mb-3">
          <div class="game-name-decorate"></div>
          <div class="game-name">{{ name }}</div>
        </div>
        <div class="row mb-3">
          <div class="col-6">
            <div class="reels-title">遊戲格式</div>
            <div class="reels-value">{{ reels }}</div>
          </div>
          <div class="col-6">
            <div class="max-win-title">最大派獎</div>
            <div class="max-win-value">{{ maxWin }}</div>
          </div>
        </div>
        <div class="mb-3">
          <div class="lines-title">遊戲線數</div>
          <div class="lines-value">{{ lines }}</div>
        </div>
        <div>
          <div class="vendor-title">遊戲供應商</div>
          <div class="vendor-value">{{ vendorName }}</div>
        </div>
      </div>
    </div>

    <div class="box mx-3 mb-3">
      <div v-if="tags.length > 0" class="mb-3">
        <div class="tag-title">遊戲標籤</div>
        <div class="d-flex flex-wrap" style="gap: 8px">
          <div v-for="(tag, index) in tags" :key="index" class="tag">
            {{ tag }}
          </div>
        </div>
      </div>
      <div>
        <div class="description-title">遊戲介紹</div>
        <div>{{ description }}</div>
      </div>
    </div>

    <div class="box mx-3 mb-3">
      <div v-if="recommendGames.length > 0" class="mb-3">
        <div class="recommend-game-title">推薦遊戲</div>
        <div v-for="g in recommendGames" :key="g.id" class="row mb-3">
          <div class="col-6 col-md-3">
            <router-link
              :to="`/game/${g.id}`"
              class="w-md-25 w-50 ratio ratio-1x1"
              style="max-height: 300px"
            >
              <img :src="g.image ? g.image : ''" class="recommend-game-img" />
            </router-link>
          </div>
          <div class="col-6 col-md-9">
            <div class="fw-bold">{{ g.name ? g.name : "" }}</div>
            <div style="white-space: pre-line">
              {{ g.description ? g.description : "-" }}
            </div>
          </div>
        </div>
      </div>
      <Introduce />
    </div>
  </div>
</template>

<script>
import Introduce from "@/components/Introduce.vue";
export default {
  components: { Introduce },
  metaInfo() {
    const { game } = this;
    if (!game) return null;

    const { title, meta_keywords, meta_description } = game;
    return {
      title: title ? title : this.name,
      meta: [
        ...(meta_keywords
          ? [{ name: "keywords", content: meta_keywords }]
          : []),
        ...(meta_description
          ? [{ name: "description", content: meta_description }]
          : []),
      ],
    };
  },
  computed: {
    gameImg() {
      const { game } = this;
      return game && game.image ? game.image : "-";
    },
    name() {
      const { game } = this;
      return game && game.name ? game.name : "-";
    },
    reels() {
      const { game } = this;
      return game && game.reels ? game.reels : "-";
    },
    maxWin() {
      const { game } = this;
      return game && game.max_win ? game.max_win : "-";
    },
    lines() {
      const { game } = this;
      return game && game.lines ? game.lines + " Ways" : "-";
    },
    vendorName() {
      const { game } = this;
      return game && game.vendor_name ? game.vendor_name : "-";
    },

    game() {
      return this.$store.getters.games.find(
        (game) => game.id == this.$route.params.gameId
      );
    },

    tags() {
      const { game } = this;
      if (!game || !game.tags) return [];
      const tags = game.tags.split(",").map((tag) => tag.trim());
      return tags;
    },
    description() {
      const { game } = this;
      return game && game.description ? game.description : "-";
    },

    recommendGames() {
      const { game } = this;
      if (!game || !game.recommend_games) return [];
      const recommendGameIds = game.recommend_games.toString().split(",");
      return this.$store.getters.games.filter((game) =>
        recommendGameIds.includes(game.id.toString())
      );
    },
  },

  watch: {
    $route(to, from) {
      if (to.params.gameId !== from.params.gameId) {
        window.scrollTo(0, 0);
      }
    },
  },
};
</script>

<style scoped>
.nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 20px;
}

.nav .left,
.nav .right {
  width: 20%;
}

.nav .center {
  width: 60%;
  text-align: center;
  font-size: 20px;
  font-weight: bold;
}

.back-img {
  width: 42px;
  height: 42px;
  cursor: pointer;
}

.game-name-decorate {
  min-height: 100%;
  width: 4px;
  margin-right: 6px;
  background-color: #1884ff;
}

.game-name {
  font-size: 20px;
  font-weight: bold;
}

.start-btn {
  border-radius: 50px;
}

.game-img {
  object-fit: cover;
  border-radius: 10px;
}

.reels-title,
.max-win-title,
.lines-title,
.vendor-title {
  font-size: 12px;
  color: #b9c6e6;
}

.box {
  background: #102341;
  border-radius: 15px;
  padding: 20px;
}

.tag-title,
.description-title,
.recommend-game-title {
  font-size: 12px;
  color: #b9c6e6;
  margin-bottom: 6px;
}

.recommend-game-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 10px;
  cursor: pointer;
}

.tag {
  background: #0d3e79;
  border-radius: 50px;
  padding: 4px 16px;
}
</style>
