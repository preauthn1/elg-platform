<template>
  <div
    v-if="loading"
    class="d-flex justify-content-center align-items-center"
    style="height: 100vh"
  >
    <div class="spinner-border text-light" role="status"></div>
  </div>
  <div id="app" v-else>
    <router-view />
  </div>
</template>

<script>
import * as XLSX from "xlsx";

export default {
  data() {
    return {
      loading: true,
    };
  },
  methods: {
    async readSheet() {
      const response = await fetch("/data.xlsx");
      const blob = await response.blob();
      const buffer = await blob.arrayBuffer();
      const workbook = XLSX.read(buffer, { type: "buffer" });
      const result = {};
      workbook.SheetNames.forEach((sheetName) => {
        const worksheet = workbook.Sheets[sheetName];
        result[sheetName] = XLSX.utils.sheet_to_json(worksheet);
      });

      this.$store.commit("setSheetData", result);
    },
  },

  async created() {
    try {
      await this.readSheet();
    } finally {
      this.loading = false;
    }
  },
};
</script>

<style>
html,
body {
  overflow-x: hidden;
}

body {
  background-color: #0e1a32;
  color: #fff;
}

.no-container-padding {
  margin-left: -15px;
  margin-right: -15px;
}
</style>
