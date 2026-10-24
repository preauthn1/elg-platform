<template>
  <div class="chat-contact">
    <v-toolbar flat dense class="chat-contact--toolbar">
      <!-- <v-text-field flat solo full-width prepend-icon="search" label="Search"></v-text-field> -->
    </v-toolbar>
    <vue-perfect-scrollbar class="chat-history--scrollbar">
      <v-divider></v-divider>
      <v-list two-line class="chat-contact--list">
        <!-- <v-subheader>Contacts</v-subheader> -->
        <v-toolbar
          flat
          dense
          class="chat-contact--toolbar"
          style="background-color:#fff; padding-bottom:8px"
        >
          <v-text-field flat solo full-width label="Search" v-model="searchName"></v-text-field>
          <v-icon @click="search()">search</v-icon>
        </v-toolbar>
        <!-- 查無好友 -->
        <div v-if="get_friendPages.pages == 0">
          <v-divider></v-divider>
          <p style="margin:0;padding:16px;text-align:center">Not Found</p>
        </div>
        <!-- 好友列表 -->
        <template v-for="(item, index) in get_friendList">
          <v-divider :key="index"></v-divider>
          <div
            v-if="index == 0 || get_friendList[index - 1].type !== item.type"
            :key="'group' + index"
            class="group"
          >
            <h5>
              {{
              item.type == 0
              ? $t('agent_group')
              : item.type == 1
              ? $t('member_group')
              : $t('group')
              }}
            </h5>
          </div>
          <v-list-tile
            avatar
            :key="index + 'friend'"
            :to="contactRoute(item)"
            @click="UsernotebyId(item)"
          >
            <v-list-tile-avatar color="primary">
              <img :src="item.thumbnail" v-if="item.thumbnail && item.thumbnail !== 'null'" />
              <img v-else :src="defaultAvatar" />
              <!-- <span v-else class="white--text headline">{{ firstLetter(item.username)}}</span> -->
            </v-list-tile-avatar>
            <v-list-tile-content>
              <v-list-tile-title>{{ item.nickname }}</v-list-tile-title>
              <v-list-tile-sub-title v-if="item.third_party_id == 0">
                {{
                item.username
                }}
              </v-list-tile-sub-title>
              <v-list-tile-sub-title v-else>
                {{
                item.third_party_id
                }}
              </v-list-tile-sub-title>
            </v-list-tile-content>

            <!-- <v-list-tile-action>
                <v-circle dot small :color="userStatusColor(item)"></v-circle>
            </v-list-tile-action>-->
          </v-list-tile>
        </template>
        <v-divider></v-divider>
        <!-- 分頁 -->
        <div class="pages">
          <div class="button" @click="turnPage(get_friendPages.previous)">
            <v-icon>keyboard_arrow_left</v-icon>
          </div>
          <div>
            {{
            get_friendPages.pages
            ? get_friendPages.current
            ? get_friendPages.current
            : 0
            : 0
            }}/{{ get_friendPages.pages ? get_friendPages.pages : 0 }}
          </div>
          <div class="button" @click="turnPage(get_friendPages.next)">
            <v-icon>keyboard_arrow_right</v-icon>
          </div>
        </div>
      </v-list>
    </vue-perfect-scrollbar>
  </div>
</template>

<script>
import { mapGetters } from 'vuex'
import { getUser } from '@/api/user'
import VCircle from '@/components/circle/VCircle'
import VuePerfectScrollbar from 'vue-perfect-scrollbar'
export default {
  components: {
    VuePerfectScrollbar,
    VCircle
  },
  data: () => ({
    defaultAvatar: require('@/../static/avatar/user.png'),
    searchName: '',
    page: 1
  }),
  computed: {
    ...mapGetters(['get_friendList', 'get_friendPages'])
  },
  methods: {
    // note 功能: UsernotebyId取記事列表 / contactRoute路由換頁
    UsernotebyId(friend) {
      this.$store.dispatch('action_FriendView', friend.member_id)
      let searchData = {
        room_id: friend.room_id
      }
      this.$store.dispatch('action_NoteList', searchData)
    },
    // 導連'/chat/noteTitle/'   *重要: NOTE功能關閉中，'/chat/noteTitle/'後，ChatNoteTitle.vue create()會直接導進聊天室
    contactRoute(item) {
      return '/chat/noteTitle/' + item.room_id + '/' + item.member_id
    },
    // firstLetter (name) {
    //     console.log(name)
    //     return name.charAt(0);
    // },
    // userStatusColor (item) {
    //     return (item.active) ? 'green' : 'grey';
    // },
    search() {
      // 搜尋不需要輸入直接按按鈕就可以
      //   if (this.searchName !== '') {
      this.page = 1
      let data = {
        status: 1,
        username: this.searchName,
        page: this.page
      }
      this.$store.dispatch('action_FriendList', data)
      //   } else {
      //     alert('Please enter key words')
      //   }
    },
    turnPage(page) {
      //好友分頁
      //   分頁不需要輸入搜尋名稱&& this.searchName !== ''
      if (page !== '') {
        this.page = page
        let data = {
          status: 1,
          username: this.searchName,
          page: this.page
        }
        this.$store.dispatch('action_FriendList', data)
      }
    }
  },
  created() {
    // NOTE 用
    if (this.$route.params.room_id && this.$route.params.member_id) {
      let params = {
        room_id: this.$route.params.room_id, // for note list
        member_id: this.$route.params.member_id //for friend view
      }
      this.UsernotebyId(params)
    } else {
      console.log('NOparams')
    }
  }
}
</script>
<style lang="css">
.pages {
  display: flex;
  justify-content: space-around;
  background-color: #f5f5f5;
  padding: 15px 0;
  text-align: center;
}
.pages div {
  width: 33%;
}
.pages .button {
  /* border: 1px solid rgba(0,0,0,.3); */
  cursor: pointer;
}
.group {
  padding: 5px 0 5px 10px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.12);
  background-color: rgba(0, 0, 0, 0.22);
}
</style>
