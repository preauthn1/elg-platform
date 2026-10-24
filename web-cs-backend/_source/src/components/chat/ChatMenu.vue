<template>
  <div class="theme--dark py-5 darken-1">
    <div>
      <v-btn icon large flat slot="activator">
        <v-avatar>
          <img v-if="userAvatar !== 'null'" :src="userAvatar?userAvatar:defaultAvatar">
          <img v-else :src="defaultAvatar">
        </v-avatar>
      </v-btn>
    </div>
    <v-list class="mini-menu">
      <!-- <template v-for="item in items"> -->
        <!-- Top level -->
        <!-- <v-list-tile :to="item.to" :key="item.icon" class="py-2 mini-tile my-2" avatar>
          <v-icon :color="item.iconColor" class="mini-icon" size="36">{{ item.icon }}</v-icon>
          
        </v-list-tile> -->
        <v-list-tile href="/chat/messaging"  class="py-2 mini-tile my-2" avatar>
            <v-icon class="mini-icon" size="36">chat</v-icon>
        </v-list-tile>
        <v-list-tile href="/chat/hide"  class="py-2 mini-tile my-2" avatar>
            <v-icon class="mini-icon" size="36">chat_bubble_outline</v-icon>
        </v-list-tile>
        <v-list-tile href="/chat/contact" class="py-2 mini-tile my-2" avatar>
            <v-icon class="mini-icon" size="36">contacts</v-icon>
        </v-list-tile>
      <!-- </template> -->
    </v-list>          
</div>
</template>

<script>
export default {
  props: {
    items: {
      type: Array,
    }
  },
  computed: {
      
  },
  data: ()=> ({
      defaultAvatar: require('@/../static/avatar/user.png'),
      userAvatar: sessionStorage.getItem('user_avatar')
  })
};
</script>

<style lang="stylus">
  .mini-tile
    a.list__tile--active
      background:hsla(0,0%,100%,.12)
 
</style>