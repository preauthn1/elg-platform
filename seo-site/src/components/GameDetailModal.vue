<template>
  <div class="modal fade" id="gameModal" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body">
          <img class="game-img" :src="image" />
          <div class="game-name">{{ name }}</div>
          <div class="game-description">{{ description }}</div>
        </div>
        <div class="modal-footer">
          <div data-bs-dismiss="modal" class="btn cancel-btn">Cancel</div>
          <div
            data-bs-dismiss="modal"
            class="btn play-btn"
            @click="$router.push(`/game/${game.id}`)"
          >
            Play
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  props: { game: Object },
  computed: {
    image() {
      return this.game ? this.game.image : "";
    },
    name() {
      return this.game ? this.game.name : "-";
    },
    description() {
      return this.game && this.game.description ? this.game.description : "";
    },
  },
};
</script>

<style scoped>
.modal-content {
  background: #1b3254;
  color: white;
}

.modal-header {
  border-bottom: none;
}

.modal-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding-top: 60px;
}

.game-img {
  width: 120px;
  height: 120px;
  object-fit: cover;
  border-radius: 50%;
  position: absolute;
  top: -60px;
  left: 50%;
  transform: translateX(-50%);
}

.game-name {
  font-size: 24px;
  font-weight: 700;
}

.game-description {
  font-size: 14px;
  color: #b9c6e6;
  text-align: center;
  white-space: pre-line;
}

.cancel-btn {
  flex: 1;
  background: #0d3e79;
  border: 2px solid #1884ff;
  border-radius: 15px;
  color: #ffffff;
}

.play-btn {
  flex: 1;
  background: #0059e7;
  border-radius: 15px;
  color: #ffffff;
}

.modal-footer {
  border-top: none;
  justify-content: center;
}
</style>
