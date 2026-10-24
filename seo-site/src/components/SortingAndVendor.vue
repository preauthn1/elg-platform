<template>
  <div>
    <div class="row">
      <div class="col-6">
        <div class="dropdown">
          <button
            class="btn btn-primary"
            data-bs-toggle="dropdown"
            aria-expanded="false"
          >
            {{ selectedSort.name }}
            <img src="../assets/img/arrow-down.png" />
          </button>
          <ul class="dropdown-menu">
            <li>
              <button
                class="dropdown-item"
                v-for="sort in sortList"
                :key="sort.id"
                @click="changeSort(sort)"
              >
                {{ sort.name }}
              </button>
            </li>
          </ul>
        </div>
      </div>
      <div class="col-6">
        <div class="dropdown">
          <button class="btn btn-primary" @click="showVendorFilter = true">
            <div>服務提供商</div>
            <img src="../assets/img/filter.png" />
            <div v-if="filterVendors.length > 0" class="badge bg-danger">
              {{ filterVendors.length }}
            </div>
          </button>
        </div>
      </div>
    </div>
    <VendorFilter v-if="showVendorFilter" @close="showVendorFilter = false" />
  </div>
</template>

<script>
import VendorFilter from "./VendorFilter.vue";
import vendorMixin from "../mixins/vendorMixin";
export default {
  mixins: [vendorMixin],
  components: {
    VendorFilter,
  },
  data() {
    return {
      showVendorFilter: false,
      selectedSort: null,
      sortList: [
        { id: 0, name: "推薦" },
        { id: 1, name: "最熱門" },
        { id: 2, name: "新遊戲" },
      ],
    };
  },

  created() {
    const querySort = this.$route.query.sort;
    this.selectedSort =
      this.sortList.find((sort) => sort.id === +querySort) || this.sortList[0];
  },

  methods: {
    changeSort(sort) {
      if (this.selectedSort.id === sort.id) return;
      this.selectedSort = sort;
      this.$router.push({ query: { ...this.$route.query, sort: sort.id } });
    },
  },
};
</script>

<style scoped>
.btn {
  background-color: #102341;
  border: none;
  color: #b9c6e6;
  text-align: left;
  border-radius: 15px;
  display: flex;
  align-items: center;
  width: 100%;
  justify-content: space-between;
}

.btn .badge {
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  position: absolute;
  right: -8px;
  top: -8px;
}

.btn img {
  width: 28px;
}

.dropdown-menu {
  background-color: #102341;
  width: 100%;
}

.dropdown-item {
  color: #b9c6e6;
}
</style>
