<template>
  <div id="pageTable">
    <v-container grid-list-xl fluid>
      <v-layout row wrap>
        <v-flex lg12>
          <v-card>
            <v-card-text>
              <v-container grid-list-md>
                <v-layout wrap>
                  <v-flex xs12>
                    <v-avatar size="150px">
                      <img
                        :src="Self.thumbnail==''||Self.thumbnail=='null'||Self.thumbnail==null?defaultAvatar:Self.thumbnail"
                      />
                    </v-avatar>
                    <!-- <label for="">頭像長寬比須為 1:1</label> -->
                    <input type="file" @change="uploadImg" />
                  </v-flex>
                  <v-flex xs12>
                    <v-text-field :label="$t('form.nickname')" v-model="Self.nickname"></v-text-field>
                  </v-flex>
                  <v-flex xs12>
                    <v-text-field :label="$t('list.balance')" v-model="balance" disabled></v-text-field>
                  </v-flex>
                  <!-- <v-flex xs12>
                                        <v-text-field
                                            :label="$t('form.new_password')"
                                            v-model="Self.password"
                                            :append-icon="p1 ? 'visibility' : 'visibility_off'"
                                            :append-icon-cb="() => (p1 = !p1)"
                                            :type="p1 ? 'password' : 'text'"
                                            counter='15'
                                        ></v-text-field>
                                    </v-flex>
                                    <v-flex xs12>
                                        <v-text-field 
                                            :label="$t('form.new_passConfirm')"
                                            v-model="Self.passConfirm"
                                            :append-icon="p2 ? 'visibility' : 'visibility_off'"
                                            :append-icon-cb="() => (p2 = !p2)"
                                            :type="p2 ? 'password' : 'text'"
                                            class="input-group--focused"
                                            counter='15'
                                        ></v-text-field>
                  </v-flex>-->
                </v-layout>
              </v-container>
            </v-card-text>
            <v-card-actions>
              <v-spacer></v-spacer>
              <v-btn color="info darken-1" @click="submit()">{{ $t('form.save') }}</v-btn>
            </v-card-actions>
          </v-card>
        </v-flex>
      </v-layout>
    </v-container>
  </div>
</template>
<script>
import { mapGetters } from 'vuex'
export default {
  computed: {
    ...mapGetters(['get_userView']),
    balance() {
      // -1等於無上限
      if (this.get_userView.limit_of_credit == '-1') {
        return this.$t('list.unlimited')
      } else {
        return (this.get_userView.limit_of_credit - this.get_userView.used_credit).toFixed(2)
      }
    }
  },
  data() {
    return {
      defaultAvatar: require('@/../static/avatar/user.png'),
      p1: true,
      p2: true,
      Self: {
        id: sessionStorage.getItem('user_id'),
        nickname: sessionStorage.getItem('user_nickname'),
        thumbnail: null,
        password: '',
        passConfirm: '',
        balance: 0
      }
    }
  },
  methods: {
    uploadImg(e) {
      //上傳頭像
      let file = e.target.files[0]
      let reader = new FileReader()
      let vm = this
      reader.readAsDataURL(file)
      reader.onload = function(event) {
        vm.Self.thumbnail = this.result

        /// 尺寸限制
        // let data = event.target.result
        // let image = new Image()
        // image.onload = ()=> {
        //     let width = image.width
        //     let height = image.height
        //     let size = width / height
        //     console.log('img',size)
        //     if(size != 1){
        //         alert('頭像尺寸長寬比應為 1:1')
        //         return false
        //     }
        // }
        // image.src = data
      }
    },
    submit() {
      let edit = {
        id: this.Self.id,
        nickname: this.Self.nickname
        // password: [REDACTED],
        // passConfirm: this.Self.passConfirm
      }
      if (this.Self.thumbnail) {
        let https = this.Self.thumbnail.split(':')
        if (https[0] !== 'https') {
          edit['thumbnail'] = this.Self.thumbnail
        }
      }
      this.$store.dispatch('action_EditSelf', edit)
    }
  },
  created() {
    if (
      sessionStorage.getItem('user_avatar') !== 'null' &&
      sessionStorage.getItem('user_avatar') !== 'undefined'
    ) {
      this.Self.thumbnail = sessionStorage.getItem('user_avatar')
    } else {
      this.Self.thumbnail = null
    }
  }
}
</script>