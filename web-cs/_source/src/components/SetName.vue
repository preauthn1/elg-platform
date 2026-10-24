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
              v-model="Nickname"
              name="nickname"
              id="nameInput"
            />
            <img src="../assets/img/closeBtn.png" @click="resetInput()" />
          </div>
          <div class="buttonAll ContentFont blueBtnPic" @click="setNickname()">
            <span>{{$t("storage")}}</span>
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
      Nickname: this.$route.params.nickname
    }
  },
  computed: {},
  mounted() {},
  methods: {
    //重新輸入
    resetInput() {
      this.Nickname = ''
    },
    //設定暱稱
    setNickname() {
      var friendId = this.$route.params.friendId
      let nickname = $('#nameInput').val()
      var data = {
        friend_id: friendId,
        nickname: nickname
      }
      var post = {
        info: data,
        url: 'api/friend/edit'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          alert(this.$t('nicknameChangeOk'))
          window.history.back()
        } else {
          alert(res.data.message)
        }
      })
    }
  }
}
</script>
<style scoped>
</style>

