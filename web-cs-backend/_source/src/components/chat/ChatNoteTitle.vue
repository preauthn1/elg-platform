<template>
  <!-- 關閉 note記事 style="display:none-->
  <!-- 記事本title 有筆跟有文件的那頁 -->
  <div style="display:none;">
    <div class="chat-note grey lighten-4">
      <v-btn flat color="primary" large @click="goChatroom()">
        <v-icon>chat</v-icon>
      </v-btn>
      <h3>{{ $t('list.notebook') }}</h3>
      <v-btn flat color="primary" large @click="addNote_Dialog()">
        <v-icon>note_add</v-icon>
      </v-btn>
    </div>
    <v-divider></v-divider>
    <vue-perfect-scrollbar class="chat-note--scrollbar">
      <v-flex lg12>
        <v-card>
          <v-divider></v-divider>
          <v-card-text class="pa-0">
            <v-data-table
              :headers="complex.headers"
              :items="get_noteList"
              :rows-per-page-items="[10, 25, 50, {text: 'All', value: -1}]"
              class="elevation-1"
              item-key="name"
              v-model="complex.selected"
            >
              <template slot="items" slot-scope="props">
                <td>{{ props.item.created_at }}</td>
                <td>{{ props.item.title }}</td>
                <td>{{ props.item.create_user }}</td>
                <td>{{ props.item.update_user }}</td>
                <td>
                  {{
                  props.item.status == 0
                  ? $t('list.unfinished')
                  : $t('list.finished')
                  }}
                </td>
                <td>
                  <v-btn
                    depressed
                    outline
                    icon
                    fab
                    dark
                    color="primary"
                    small
                    @click="editNote_Dialog(props.item)"
                  >
                    <v-icon>edit</v-icon>
                  </v-btn>
                  <v-btn
                    depressed
                    outline
                    icon
                    fab
                    dark
                    color="primary"
                    small
                    @click="description(props.item)"
                  >
                    <v-icon>description</v-icon>
                  </v-btn>
                </td>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-flex>
    </vue-perfect-scrollbar>
    <!-- 新增note -->
    <v-dialog id="addNote" v-model="addNote.dialog" persistent max-width="500px">
      <v-card>
        <v-card-title style="justify-content:space-between">
          <span class="headline">{{ $t('form.createNote') }}</span>
          <small>{{ $t('form.Asterisk_Required') }}</small>
        </v-card-title>
        <v-divider></v-divider>
        <v-card-text>
          <v-container grid-list-md>
            <v-layout wrap>
              <v-flex xs12>
                <v-text-field
                  :label="$t('form.username')"
                  :value="get_friendView.username"
                  disabled
                ></v-text-field>
              </v-flex>
              <v-flex xs12>
                <v-text-field
                  :label="$t('form.noteTitle') + ' *'"
                  v-model="addNote.note_title"
                  v-validate="'required'"
                  :data-vv-name="$t('form.noteTitle')"
                  :error-messages="errors.collect($t('form.noteTitle'))"
                ></v-text-field>
              </v-flex>
              <v-flex xs12>
                <v-select
                  :items="Status"
                  v-model="addNote.status"
                  :label="$t('list.status')"
                  item-text="text"
                  item-value="val"
                ></v-select>
              </v-flex>
            </v-layout>
          </v-container>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="blue darken-1" flat @click.native="clear()">
            {{
            $t('form.cancel')
            }}
          </v-btn>
          <v-btn color="blue darken-1" flat @click="addNote_Title()">
            {{
            $t('form.create')
            }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
    <!-- 編輯 -->
    <v-dialog id="EditNote" v-model="EditNote.dialog" persistent max-width="500px">
      <v-card>
        <v-card-title style="justify-content:space-between">
          <span class="headline">{{ $t('form.editNoteTitle') }}</span>
          <small>{{ $t('form.Asterisk_Required') }}</small>
        </v-card-title>
        <v-divider></v-divider>
        <v-card-text>
          <v-container grid-list-md>
            <v-layout wrap>
              <v-flex xs12>
                <v-text-field
                  :label="$t('form.username')"
                  :value="get_friendView.username"
                  disabled
                ></v-text-field>
              </v-flex>
              <v-flex xs12>
                <v-text-field
                  :label="$t('form.noteTitle') + ' *'"
                  v-model="EditNote.note_title"
                  v-validate="'required'"
                  :data-vv-name="$t('form.editTitle')"
                  :error-messages="errors.collect($t('form.editTitle'))"
                ></v-text-field>
              </v-flex>
              <v-flex xs12>
                <v-select
                  :items="Status"
                  v-model="EditNote.status"
                  :label="$t('list.status')"
                  item-text="text"
                  item-value="val"
                ></v-select>
              </v-flex>
            </v-layout>
          </v-container>
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="blue darken-1" flat @click.native="clear()">
            {{
            $t('form.cancel')
            }}
          </v-btn>
          <v-btn color="blue darken-1" flat @click="editNote_Title()">
            {{
            $t('form.save')
            }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
import VuePerfectScrollbar from 'vue-perfect-scrollbar'
export default {
  components: {
    VuePerfectScrollbar
  },
  computed: {
    ...mapGetters(['get_friendView', 'get_noteList'])
  },
  data() {
    return {
      chat: null,
      selectedTab: null,
      complex: {
        selected: [],
        headers: [
          {
            text: this.$t('list.dateTime'),
            value: 'created_at'
          },
          {
            text: this.$t('list.title'),
            value: 'title'
          },
          {
            text: this.$t('list.create_user'),
            value: 'create_user'
          },
          {
            text: this.$t('list.update_user'),
            value: 'update_user'
          },
          {
            text: this.$t('list.status'),
            value: 'status'
          },
          {
            text: this.$t('list.action'),
            value: ''
          }
        ]
        // items: Users
      },
      Status: [
        { text: this.$t('list.unfinished'), val: 0 },
        { text: this.$t('list.finished'), val: 1 }
      ],
      addNote: {
        dialog: false,
        // room_id: '',
        note_title: '',
        status: 0
      },
      EditNote: {
        dialog: false,
        room_id: '',
        note_id: '',
        note_title: '',
        status: null
      }
    }
  },
  methods: {
    // note進聊天室
    goChatroom() {
      // console.log('goChatroom',this.get_friendView.friend_id)
      let friend_id = this.get_friendView.friend_id
      this.$router.push(
        '/chat/messaging/' +
          this.$route.params.room_id +
          '/' +
          this.$route.params.member_id +
          '/friend/' +
          friend_id
      )
    },
    // 新增
    addNote_Dialog() {
      this.addNote.dialog = true
    },
    addNote_Title() {
      this.$validator.validate(this.$t('form.noteTitle')).then(vaild => {
        if (vaild) {
          let addnote_Data = {
            room_id: this.$route.params.room_id,
            title: this.addNote.note_title,
            status: this.addNote.status
          }
          this.$store.dispatch('action_addNote_Title', addnote_Data)
          this.addNote.dialog = false
        } else {
          console.log('未填記事本標題')
        }
      })
    },
    // 編輯
    editNote_Dialog(note) {
      this.EditNote.dialog = true
      this.EditNote.room_id = note.room_id
      this.EditNote.note_id = note.id
      this.EditNote.note_title = note.title
      this.EditNote.status = note.status
    },
    editNote_Title() {
      this.$validator.validate(this.$t('form.editTitle')).then(vaild => {
        if (vaild) {
          this.EditNote.dialog = false
          let note = {
            room_id: this.EditNote.room_id,
            note_id: this.EditNote.note_id,
            title: this.EditNote.note_title,
            status: this.EditNote.status
          }
          this.$store.dispatch('action_editNote_Title', note)
        }
      })
    },
    clear() {
      this.$validator.reset()
      this.addNote.dialog = false
      this.EditNote.dialog = false
    },
    // NOTE 內容
    description(item) {
      this.$router.push(
        '/chat/noteContent/' + item.id + '/' + item.member_id + '/' + item.title
      )
    }
  },
  created() {
    // 直進聊天室版!!!! note功能關閉
    setTimeout(() => {
      this.goChatroom()
    }, 1200)
  }
}
</script>
<style lang="css" scoped>
.chat-note {
  display: flex;
  justify-content: space-between;
}
.chat-note h3 {
  line-height: 56px;
  text-align: center;
}
.chat-note--scrollbar {
  position: relative;
  height: calc(100vh - 361px);
}
</style>
