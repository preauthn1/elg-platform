<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div></div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
import { mapGetters, mapActions } from 'vuex'
export default {
  components: {
    listTitle
  },
  mounted() {},
  methods: {
    //   ...mapActions([])
  }

  //   computed: {
  //     ...mapGetters([])
  //   }
}
</script>
<style scope>
</style>