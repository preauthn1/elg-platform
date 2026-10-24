<template>
  <div class="box">
    <div class="content">
      <div class="header">
        <div class="title">服務提供商</div>
        <img
          class="close-img"
          src="../assets/img/close.png"
          @click="$emit('close')"
        />
      </div>
      <div class="vendor-list">
        <div class="row row-cols-2 row-cols-md-6 g-3 p-3">
          <div class="col" v-for="vendor in vendors" :key="vendor.id">
            <div
              @click="selectVendor(vendor)"
              class="vendor"
              :class="{ selected: isSelectedVendor(vendor) }"
            >
              <img :src="vendor.image" class="vendor-img w-100" />
              <img
                v-if="isSelectedVendor(vendor)"
                src="../assets/img/selected.png"
                class="selected-img"
              />
            </div>
          </div>
        </div>
      </div>
      <div class="footer">
        <button class="btn btn-secondary w-100" @click="selectedVendors = []">
          清除
        </button>
        <button class="btn btn-primary w-100" @click="confirm">確定</button>
      </div>
    </div>
  </div>
</template>

<script>
import vendorMixin from "../mixins/vendorMixin";
export default {
  mixins: [vendorMixin],
  computed: {
    isSelectedVendor() {
      return (vendor) => this.selectedVendors.includes(vendor);
    },
  },
  data() {
    return {
      selectedVendors: [],
    };
  },
  methods: {
    selectVendor(vendor) {
      if (this.selectedVendors.includes(vendor)) {
        this.selectedVendors = this.selectedVendors.filter((v) => v !== vendor);
      } else {
        this.selectedVendors.push(vendor);
      }
    },
    confirm() {
      this.$emit("close");
      const vendors = this.selectedVendors.map((v) => v.id).join(",");
      this.$router.push({
        query: {
          ...this.$route.query,
          vendors: vendors.length > 0 ? vendors : undefined,
        },
      });
    },
  },
  created() {
    this.selectedVendors = [...this.filterVendors];
  },
};
</script>

<style scoped>
.box {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  background-color: #0e1a32;
  z-index: 1000;
}

.content {
  height: 100%;
  display: flex;
  flex-direction: column;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 24px;
  background-color: #102341;
}

.header .title {
  font-size: 20px;
  font-weight: 700;
}

.close-img {
  width: 40px;
  height: 40px;
  cursor: pointer;
}

.vendor-list {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
}

.vendor {
  border: 1px solid #b9c6e6;
  border-radius: 15px;
  padding: 12px 18px;
  cursor: pointer;
  position: relative;
}

.vendor.selected {
  border: 1px solid #1884ff;
}

.selected-img {
  position: absolute;
  top: 10%;
  right: 5%;
  width: 20px;
  height: 20px;
}

.vendor .vendor-img {
  height: 80px;
  object-fit: contain;
}

.footer {
  background-color: #102341;
  display: flex;
  gap: 10px;
  padding: 14px 24px;
}
</style>
