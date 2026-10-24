<template>
  <div class="row row-cols-3 row-cols-md-6 g-3">
    <div
      v-for="game in sortedGames"
      :key="game.id"
      class="col item"
      :class="{ disabled: game.disable == 1 }"
    >
      <div class="ratio ratio-1x1">
        <img
          :src="game.image"
          :alt="game.name"
          class="icon"
          @click="selectedGame = game"
        />
        <img src="../assets/img/hot.gif" class="hot" v-if="game.is_hot == 1" />
        <img src="../assets/img/new.png" class="new" v-if="game.is_new == 1" />
        <div class="mask" v-if="selectedGame === game">
          <router-link :to="`/game/${game.id}`" class="play-link">
            <img src="../assets/img/play.png" />
          </router-link>
          <div @click="setModalGame(game)" class="description">遊戲介紹</div>
          <img
            src="../assets/img/favourite0.png"
            class="favourite-icon"
            v-if="!isFavourite(game)"
            @click="$store.commit('addFavoriteGameId', game.id)"
          />

          <img
            src="../assets/img/favourite1.png"
            class="favourite-icon"
            v-if="isFavourite(game)"
            @click="$store.commit('removeFavoriteGameId', game.id)"
          />
        </div>
      </div>
    </div>
    <GameDetailModal :game="selectedModalGame" />
  </div>
</template>

<script>
import { Modal } from "bootstrap";
import GameDetailModal from "./GameDetailModal.vue";

export default {
  components: { GameDetailModal },
  props: {
    games: Array,
    sortId: [String, Number],
  },
  computed: {
    sortedGames() {
      const { sortId, games } = this;
      if (sortId == 1) {
        return games.sort((a, b) => +b.is_hot - +a.is_hot);
      }
      if (sortId == 2) {
        return games.sort((a, b) => +b.is_new - +a.is_new);
      }
      return games;
    },
    isFavourite() {
      return (game) =>
        this.$store.state.favoriteGameIds.includes(game.id.toString());
    },
  },
  data() {
    return {
      selectedGame: null,
      selectedModalGame: null,
    };
  },
  methods: {
    openModal() {
      const element = document.getElementById("gameModal");
      const modal = new Modal(element);
      modal.show();
    },

    async setModalGame(game) {
      this.selectedModalGame = game;
      this.openModal();
    },
  },
};
</script>

<style scoped>
.disabled {
  display: none;
}

.icon {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 10px;
  cursor: pointer;
}

.item .new {
  position: absolute;
  top: -8%;
  right: -8%;
  width: 30%;
  height: 30%;
  object-fit: contain;
  pointer-events: none;
}

.hot {
  position: absolute;
  top: -12%;
  left: -9%;
  width: 119%;
  height: 125%;
  pointer-events: none;
}

.mask {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  border-radius: 10px;
  background-color: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  color: #fff;
}

.mask .play-link {
  width: 36%;
  height: 36%;
  object-fit: contain;
  margin-top: 10%;
  margin-bottom: 4px;
  cursor: pointer;
}

.mask .play-link img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.mask .description {
  font-size: 14px;
  text-decoration: none;
  color: #fff;
  cursor: pointer;
}

.mask .favourite-icon {
  width: 15%;
  height: 15%;
  position: absolute;
  top: 8px;
  right: 8px;
  object-fit: contain;
  cursor: pointer;
}
</style>
