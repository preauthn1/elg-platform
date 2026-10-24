<template>
  <div class="chat-history">
    <!-- <v-toolbar dense class="chat-history-toolbar">
      <v-text-field flat solo full-width clearable prepend-icon="search" label="Search"></v-text-field>
    </v-toolbar> -->
    <vue-perfect-scrollbar class="chat-history--scrollbar">
      <v-divider></v-divider>             
      <v-list two-line class="chat-history--list" style="padding-top:0">
        <v-subheader>History (Hide)</v-subheader>
        <template v-for="(item, index) in get_roomList_hide">
          <v-divider :key="index"></v-divider>
          <v-list-tile class="chat-list" :class="{ active:chatStatusColor(item)}" avatar :key="index + 'room'" :to="chatRoute(item)" @click="roomEnter(item)">
            <v-list-tile-avatar>
            <!-- <v-list-tile-avatar :color="randomAvatarColor(item)"> -->
              <img v-if="item.thumbnail!=='null'" :src="item.thumbnail?item.thumbnail:defaultAvatar">
              <img v-else :src="defaultAvatar">
              <!-- <img :src="item.user.avatar" v-if="item.users.length === 1">
              <span v-else class="white--text headline">{{ firstLetter(item.title)}}</span> -->
            </v-list-tile-avatar>
            <v-list-tile-content>
              <v-list-tile-title>{{item.name}}</v-list-tile-title>
              <v-list-tile-sub-title >{{item.message_content}}</v-list-tile-sub-title>
            </v-list-tile-content>
            <v-list-tile-action>
              <v-list-tile-action-text>
                {{ formatChatTime(item.message_time) }}
              </v-list-tile-action-text>
              <!-- 豆豆隱藏 -->
              <!-- <v-circle dot small :color="chatStatusColor(item)"></v-circle> -->
              <v-circle dot small></v-circle>
            </v-list-tile-action>
          </v-list-tile>
        </template>
      </v-list>  
    </vue-perfect-scrollbar>  
  </div>
</template>

<script>
require('howler');
import { mapGetters } from 'vuex'
import { Groups } from '@/api/chat';
import { getUserById } from '@/api/user';
import VCircle from '@/components/circle/VCircle';
import Util from '@/util';
import VuePerfectScrollbar from 'vue-perfect-scrollbar';
import { watch } from 'fs';
export default {
    components: {
        VuePerfectScrollbar,
        VCircle
    },
    computed: {
        ...mapGetters(['get_roomList_hide','get_newMark'])
    },
    data: () => ({
        defaultAvatar: require('@/../static/avatar/user.png'),
        chats: Groups,
        sound_count: 0
    }),
    methods: {
        roomEnter(room) { //進房間
            this.$store.commit('clearMark',room.code)
            let data = {
                "code": room.code?room.code:'', 
                "friend_id": room.friend_id?room.friend_id:''
            }
            this.$store.dispatch('action_RoomEnter',data)
        },
        //////////////////
        chatRoute (item) { //導去聊天室頁
            return '/chat/messaging/' + item.id + '/' + item.members +'/'+ item.code;
        },
        // firstLetter (title) {
        //     return title.charAt(0);
        // },
        formatChatTime (s) { //時間轉換
            let newDate
            if(s){
                newDate = s.replace(/-/g,'/')
            }
            return new Date(newDate).toLocaleDateString();
        },
        // computeTitle (item) {
        //     let username = (item.users.length === 1) ? getUserById(item.users[0]).username : '';
        //     return item.users.length === 1 ? username : item.title;
        // },
        // randomAvatarColor (item) {
        //     return item.users.length === 1 
        //     ? ''
        //     : Util.randomElement(['blue', 'indigo', 'success', 'error', 'pink']);
        // },
        chatStatusColor (item) { //新訊息標記
            if(this.get_newMark.includes(item.code)){
                return true
            }else {
                return false
            }
        },
    },
    created() {
        this.$store.dispatch('action_RoomList_Status',2) //撈隱藏的聊天室列表
    },
};
</script>
<style lang="css" scoped>
.active {
    background-color: rgba(255,230,190,1);
}
@media screen and (max-width: 959px) {
    .chat-history--scrollbar {
        height: calc(100vh - 56px)
    }
    .v-subheader {
        height: 56px
    }
}

</style>
