<template>
  <v-app id="chat" class="chat">
    <template v-if="!$vuetify.breakpoint.smAndDown">
      <v-navigation-drawer
        class="pa-0 chat-drawer primary"
        fixed
        permanent
        app
        width="68"
        >
        <chat-menu :items="menus" class="chat-drawer--menu">
        </chat-menu>        
      </v-navigation-drawer>
      <v-content class="chat-main">
        <transition>
          <router-view></router-view>
        </transition> 
      </v-content>
    </template>  
    <template v-else>
      <v-toolbar color="primary" fixed dark>
        <v-btn v-if="!hideArrow" icon @click="handleClick">
          <v-icon>keyboard_arrow_left</v-icon>
        </v-btn>
        <v-spacer>
            <h4 v-if="$route.meta.inRoom" style="text-align:center;">{{get_roomMsg.room.name}}</h4>
        </v-spacer>
        <v-btn icon>
          <!-- <v-icon>more_vert</v-icon> -->
        </v-btn>        
      </v-toolbar>
      <v-content class="chat-main">
        <transition>
          <router-view></router-view>
        </transition> 
      </v-content>      
      <v-bottom-nav :value="true" absolute color="primary" app fixed v-if="!hideBottomNav">
        <v-btn dark flat :value="item.to.path" v-for="(item, index) in menus" :key="index" :to="item.to">
          <span>{{item.text}}</span>
          <v-icon>{{item.icon}}</v-icon>
        </v-btn>
      </v-bottom-nav>      
    </template>
  </v-app>
</template>

<script>
import { mapGetters } from 'vuex'
import API from '@/api';
import ChatMenu from './ChatMenu';
import VuePerfectScrollbar from 'vue-perfect-scrollbar';
export default {
  components: {
    VuePerfectScrollbar,
    ChatMenu
  },
  data: () => ({
    menus: API.getChatMenu,
  }),
  computed: {
    ...mapGetters(['get_roomMsg']),
    hideArrow() {
        return this.$route.meta.hideArrow;
    },
    hideBottomNav () {
    //   return this.$route.params.uuid !== undefined && this.$route.name === 'ChatMessaging';
      return this.$route.meta.inRoom;
    },
  },
  methods: {
    handleClick () {
        let pathname = this.$route.fullPath.split('/')
        if(pathname[2] == 'messaging'){
            this.$router.push('/chat/messaging')
        }
    //   this.$router.go(-1);
    }
  }
};
</script>