<template>
  <div class="all" id="content">
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="listbgPic" style="height:100vh;">
      <div class="blackList allContent">
        <div v-for="(friend,i) in friendList">
          <div class="checkBtn float-left" v-show="isShow">
            <div class="checkBP">
              <b-form-checkbox
                name="checkboxBlock"
                v-model="selected"
                class="blockCheckbox"
                unchecked-value="not_accepted"
                :value="friend.friend_id"
                @change="blockSelected"
              />
            </div>
          </div>
          <div class="blackGroup clearfix" @click="checkBlock">
            <div class="blackPic float-left">
              <img
                :src=" friend.thumbnail===null||friend.thumbnail==='null'||friend.thumbnail===''?DefaultAvatar:friend.thumbnail "
              />
            </div>
            <div class="blackFont ContentFont float-left">{{friend.nickname}}</div>
          </div>
        </div>
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
      isShow: false,
      DefaultAvatar: require('../../static/img/user.jpg'),
      friendList: '',
      selected: []
    }
  },
  mounted() {
    $('.titleCenter').html(this.$t('blockList'))
    //取黑名單列表
    var store = this.$store
    let sign = sessionStorage.getItem('sign')
    if (sign == 'undefined') {
      sign = this.$route.params.sign
      sessionStorage.setItem('sign', sign)
    }
    if (sign != 'undefined') {
      var sign_data = JSON.parse(sign)
      var data = {
        USERNAME: sign_data.username,
        THUMBNAIL: sign_data.avatar,
        NICKNAME: sign_data.nickname,
        status: 2
      }
      var post = {
        info: data,
        url: 'api/friend/list'
      }
      this.$store.dispatch('post', post).then(res => {
        if (res.data.code == 1000) {
          this.friendList = res.data.data
        } else {
          alert(res.data.message)
        }
      })
    } else {
      window.location = '/404'
    }
    //加入/解除黑名單
    $('#blockDisable').attr('disabled', 'true')
    $('#blockDisable').css('color', 'gray')
    $('#blockDisable').click(function() {
      $("input[type='checkbox']:checked").each(function() {
        var data = {
          friend_id: $(this).val(),
          status: 1
        }
        var post = {
          info: data,
          url: 'api/friend/edit'
        }
        store.dispatch('post', post).then(res => {
          window.location.reload()
          //   if (res.data.code != 1000) {
          // console.log(res, '編輯黑名單')
          // alert(res.data.message)
          // window.history.back()
          //   } else {
          // alert(res.data.message)
          //   }
        })
      })
      //   window.history.back()
    })
  },
  methods: {
    checkBlock: function() {
      this.isShow = !this.isShow
      if (this.isShow) {
        $('.checkBtn').show()
      } else {
        $('.checkBtn').hide()
      }
    },
    blockSelected: function() {
      var checkedAmount = $("input[type='checkbox']:checked").length
      if (checkedAmount > 0) {
        $('#blockDisable').attr('disabled', false)
        $('#blockDisable').css('color', 'white')
        $('.titleCenter').html(this.$t('selected') + '(' + checkedAmount + ')')
      } else {
        $('#blockDisable').attr('disabled', true)
        $('#blockDisable').css('color', 'gray')
        $('.titleCenter').html(this.$t('blockList'))
      }
    }
  }
  //   watch:{

  //   }
}
</script>
<style scoped>
</style>