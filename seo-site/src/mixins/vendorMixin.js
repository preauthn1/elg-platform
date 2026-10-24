export default {
  computed: {
    vendors() {
      return this.$store.state.sheetData.vendors.filter((v) => v.status == 1);
    },
    filterVendorIds() {
      return this.filterVendors.map((v) => v.id);
    },
    filterVendors() {
      const queryVendors = this.$route.query.vendors;
      if (!queryVendors) return [];
      const queryList = queryVendors.split(",");
      return this.vendors.filter((v) => queryList.includes(v.id.toString()));
    },
  },
};
