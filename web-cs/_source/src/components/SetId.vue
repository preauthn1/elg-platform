<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="listbgPic" style="height:100vh;">
      <div class="setNameInput allContent">
        <form>
          <div class="inputBgColor inputBorder inputBorderColor ContentFont inputAll">
            <input
              type="text"
              placeholder
              class="inputTagBg"
              v-model="myUsername"
              name="myUsername"
              id="idInput"
            />
            <img src="../assets/img/closeBtn.png" id="closeBtn" @click="resetInput()" />
          </div>
          <div
            class="myidFont"
            v-if="beforeUsername==''||beforeUsername==null||beforeUsername=='null'"
          >{{$t("cannotAfterTheIdSet")}}</div>
          <div class="myidFont" v-else>{{$t("youHaveSetAnID")}}</div>
          <div class="sendIdButton">
            <div
              class="buttonAll ContentFont blueBtnPic"
              @click="setID()"
              v-if="beforeUsername==''||beforeUsername==null||beforeUsername=='null'"
            >
              <span>{{$t("storage")}}</span>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
<script>
import listTitle from '@/components/ListTitle'
export default {
  components: {
    listTitle
  },
  data() {
    return {
      myUsername: '',
      beforeUsername: ''
    }
  },
  computed: {},
  mounted() {
    $('.titleCenter').html(this.$t('setId'))
    //會員帳號資訊
    var post = {
      info: {},
      url: 'api/member/self'
    }
    this.$store.dispatch('post', post).then(res => {
      if (res.data.code == 1000) {
        this.beforeUsername = res.data.data.username
        if (
          this.beforeUsername == '' ||
          this.beforeUsername == null ||
          this.beforeUsername == 'null'
        ) {
        } else {
          this.myUsername = this.beforeUsername
          $('#idInput').attr('disabled', true)
          $('#sendBtn').attr('disabled', true)
          $('#closeBtn').css('display', 'none')
        }
        var userNa = this.beforeUsername
      } else {
        alert(res.data.message)
      }
    })
  },
  methods: {
    resetInput() {
      this.myUsername = ''
    },
    //設定ID
    setID() {
      if (
        this.beforeUsername === '' ||
        this.beforeUsername === null ||
        this.beforeUsername === 'null'
      ) {
        var data = {
          username: this.myUsername
        }
        var post = {
          info: data,
          url: 'api/member/edit'
        }
        this.$store.dispatch('post', post).then(res => {
          if (res.data.code == 1000) {
            alert(this.$t('idChangeOk'))
            window.history.back()
          } else {
            alert(res.data.message)
          }
        })
      } else {
        alert(this.$t('idHasBeenChanged'))
      }
    }
  }
}
</script>
<style scoped>
</style>

