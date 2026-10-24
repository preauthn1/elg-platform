<template>
  <!-- 記事本內容 文件點進去的那頁 -->
  <div>
    <div class="chat-note grey lighten-4">
      <v-btn flat color="primary" large @click="backNoteTitle()">
        <v-icon>keyboard_arrow_left</v-icon>
      </v-btn>
      <h3>{{ $route.params.title }}</h3>
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
              :items="get_noteContent_List"
              :rows-per-page-items="[10, 25, 50, {text: 'All', value: -1}]"
              class="elevation-1"
              item-key="name"
              v-model="complex.selected"
            >
              <template slot="items" slot-scope="props">
                <td>{{ props.item.created_at }}</td>
                <td>{{ props.item.content }}</td>
                <td>{{ props.item.create_user }}</td>
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
                    color="pink"
                    small
                    @click="delNote_Dialog(props.item)"
                  >
                    <v-icon>delete</v-icon>
                  </v-btn>
                </td>
              </template>
            </v-data-table>
          </v-card-text>
        </v-card>
      </v-flex>
    </vue-perfect-scrollbar>
    <!-- 新增 -->
    <v-dialog
      id="addNote"
      v-model="AddNote.dialog"
      persistent
      max-width="500px"
    >
      <v-card>
        <v-card-title style="justify-content:space-between">
          <span class="headline">{{ $t('form.createContent') }}</span>
          <small>{{ $t('form.Asterisk_Required') }}</small>
        </v-card-title>
        <v-divider></v-divider>
        <v-card-text>
          <!-- <v-container grid-list-md> -->
          <v-layout wrap>
            <v-flex xs12>
              <v-text-field
                :label="$t('form.noteTitle')"
                :value="$route.params.title"
                disabled
              ></v-text-field>
            </v-flex>
            <v-flex lg12 sm12>
              <!-- 備註用 -->
              <v-textarea
                textarea
                :label="$t('form.noteContent') + ' *'"
                v-model="AddNote.content"
                v-validate="'required'"
                :data-vv-name="$t('form.noteContent')"
                :error-messages="errors.collect($t('form.noteContent'))"
              ></v-textarea>
            </v-flex>
          </v-layout>
          <!-- </v-container> -->
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="blue darken-1" flat @click.native="clear()">{{
            $t('form.cancel')
          }}</v-btn>
          <v-btn color="blue darken-1" flat @click="addNote_Content()">{{
            $t('form.create')
          }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
    <!-- 編輯 -->
    <v-dialog
      id="editNote"
      v-model="EditNote.dialog"
      persistent
      max-width="500px"
    >
      <v-card>
        <v-card-title style="justify-content:space-between">
          <span class="headline">{{ $t('form.editNoteContent') }}</span>
          <small>{{ $t('form.Asterisk_Required') }}</small>
        </v-card-title>
        <v-divider></v-divider>
        <v-card-text>
          <!-- <v-container grid-list-md> -->
          <v-layout wrap>
            <v-flex xs12>
              <v-text-field
                :label="$t('form.noteTitle')"
                :value="$route.params.title"
                disabled
              ></v-text-field>
            </v-flex>
            <v-flex lg12 sm12>
              <v-textarea
                textarea
                :label="$t('form.noteContent') + ' *'"
                v-model="EditNote.content"
                v-validate="'required'"
                :data-vv-name="$t('form.editContent')"
                :error-messages="errors.collect($t('form.editContent'))"
              ></v-textarea>
            </v-flex>
          </v-layout>
          <!-- </v-container> -->
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="blue darken-1" flat @click.native="clear()">{{
            $t('form.cancel')
          }}</v-btn>
          <v-btn color="blue darken-1" flat @click="editNote_Content()">{{
            $t('form.save')
          }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
    <!-- 刪除 -->
    <v-dialog
      id="deleteNote"
      v-model="DelNote.dialog"
      persistent
      max-width="500px"
    >
      <v-card>
        <v-card-title>
          <p class="headline" style="margin:auto">
            {{ $t('form.delContent_confirm') }}
          </p>
        </v-card-title>
        <v-divider></v-divider>
        <v-card-text>
          <!-- <v-container grid-list-md> -->
          <v-layout wrap>
            <v-flex lg12 sm12>
              <v-textarea
                textarea
                :label="$t('form.noteContent')"
                :value="DelNote.content"
                disabled
              ></v-textarea>
            </v-flex>
          </v-layout>
          <!-- </v-container> -->
        </v-card-text>
        <v-card-actions>
          <v-spacer></v-spacer>
          <v-btn color="blue darken-1" flat @click.native="clear()">{{
            $t('form.cancel')
          }}</v-btn>
          <v-btn color="blue darken-1" flat @click="delNote_Content()">{{
            $t('form.confirm')
          }}</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>
<script>
import {mapGetters, mapActions} from 'vuex'
import {getUserById} from '@/api/user'
import VuePerfectScrollbar from 'vue-perfect-scrollbar'
export default {
  components: {
    VuePerfectScrollbar,
  },
  computed: {
    ...mapGetters(['get_friendView', 'get_noteContent_List']),
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
            value: 'created_at',
          },
          {
            text: this.$t('list.content'),
            value: 'content',
          },
          {
            text: this.$t('list.create_user'),
            value: 'created_user',
          },
          {
            text: this.$t('list.action'),
            value: '',
          },
        ],
        // items: Users
      },
      AddNote: {
        dialog: false,
        content: '',
      },
      EditNote: {
        dialog: false,
        content_id: '',
        content: '',
      },
      DelNote: {
        dialog: false,
        content_id: '',
        content: '',
      },
    }
  },
  methods: {
    getContent_List() {
      if (this.$route.params.note_id && this.$route.params.member_id) {
        // this.$store.dispatch('action_FriendView', this.$route.params.member_id)
        let data = {
          note_id: this.$route.params.note_id,
        }
        this.$store.dispatch('action_NoteContentList', data)
      } else {
        console.log('no params')
      }
    },
    backNoteTitle() {
      this.$router.go(-1)
    },
    // 新增
    addNote_Dialog() {
      this.AddNote.dialog = true
    },
    addNote_Content() {
      this.$validator.validate(this.$t('form.noteContent')).then(vaild => {
        if (vaild) {
          let addnote_Data = {
            note_id: this.$route.params.note_id,
            content: this.AddNote.content,
          }
          this.$store.dispatch('action_addNote_Content', addnote_Data)
          this.AddNote.dialog = false
        }
      })
    },
    // 編輯
    editNote_Dialog(note) {
      this.EditNote.dialog = true
      this.EditNote.content = note.content
      this.EditNote.content_id = note.id
    },
    editNote_Content() {
      this.$validator.validate(this.$t('form.editContent')).then(vaild => {
        if (vaild) {
          let editnote_Data = {
            note_id: this.$route.params.note_id,
            content_id: this.EditNote.content_id,
            content: this.EditNote.content,
          }
          this.$store.dispatch('action_editNote_Content', editnote_Data)
          this.EditNote.dialog = false
        }
      })
    },
    // 刪除
    delNote_Dialog(note) {
      this.DelNote.dialog = true
      this.DelNote.content = note.content
      this.DelNote.content_id = note.id
    },
    delNote_Content() {
      this.DelNote.dialog = false
      let delnote_Data = {
        note_id: this.$route.params.note_id,
        content_id: this.DelNote.content_id,
      }
      this.$store.dispatch('action_delete_Content', delnote_Data)
    },
    clear() {
      this.$validator.reset()
      this.AddNote.dialog = false
      this.EditNote.dialog = false
      this.DelNote.dialog = false
    },
  },
  created() {
    this.getContent_List()
  },
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
