import Vue from "vue";
import Vuex from "vuex";

Vue.use(Vuex);

const localStorageUpdate = (state) => {
  localStorage.setItem(
    "favoriteGameIds",
    JSON.stringify(state.favoriteGameIds)
  );
};

export default new Vuex.Store({
  state: {
    sheetData: {},
    favoriteGameIds: [
      ...(JSON.parse(localStorage.getItem("favoriteGameIds")) || []),
    ],
  },
  getters: {
    games: (state) => state.sheetData.games.filter((game) => game.id != null),
  },
  mutations: {
    setSheetData(state, payload) {
      state.sheetData = payload;
    },

    setFavoriteGameIds(state, payload) {
      state.favoriteGameIds = payload;
    },

    addFavoriteGameId(state, payload) {
      if (state.favoriteGameIds.includes(payload)) return;
      state.favoriteGameIds.push(payload);
      localStorageUpdate(state);
    },

    removeFavoriteGameId(state, payload) {
      state.favoriteGameIds = state.favoriteGameIds.filter(
        (id) => id !== payload
      );
      localStorageUpdate(state);
    },
  },
  actions: {},
  modules: {},
});
