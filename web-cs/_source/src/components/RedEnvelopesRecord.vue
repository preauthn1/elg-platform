<template>
  <div>
    <div class="listTitleHeight">
      <redRHeader />
    </div>
    <div class="allContent whiteAll">
      <div class="whiteCon">
        <!-- <div class="recordTop">
          <div class="recordPic">
            <img src="../assets/img/pic2.jpg" />
          </div>
          <div class="recordItem">QC2000的紅包</div>

          <div class="recordMoney">目前餘額:150.00</div>
        </div>-->

        <div class="RrecordList">
          <div class="RrecordListCon">
            <table class="table table-striped">
              <thead>
                <tr>
                  <th scope="col">{{$t('date')}}</th>
                  <th scope="col">{{$t('friend')}}</th>
                  <th scope="col">{{$t('type')}}</th>
                  <th scope="col">{{$t('vpoint')}}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(redEnvel,i) in redEnvelList" v-if="$route.name=='RedSend'">
                  <td>{{redEnvel.send_time}}</td>
                  <td>{{redEnvel.sender_username ? redEnvel.sender_username:$t('notYetReceived')}}</td>
                  <td>{{redEnvel.type == 1? $t('personalRedEnvelope') :(redEnvel.type == 2)?$t('groupRedEnvelope'):$t('random')}}</td>
                  <td>{{redEnvel.amount}}</td>
                </tr>
                <tr v-for="(redEnvel,i) in redEnvelList" v-if="$route.name=='RedReceive'">
                  <td>{{redEnvel.receive_time ? redEnvel.receive_time:$t('notYetReceived')}}</td>
                  <td>{{redEnvel.receiver_username ? redEnvel.receiver_username:$t('notYetReceived')}}</td>
                  <td>{{redEnvel.type == 1? $t('personalRedEnvelope') :(redEnvel.type == 2)?$t('groupRedEnvelope'):$t('random')}}</td>
                  <td>{{redEnvel.amount}}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import redRHeader from '@/components/redRecordHeader'
export default {
  components: {
    redRHeader
  },
  data() {
    return {
      redEnvelList: ''
    }
  },
  mounted() {
    //會員帳號資訊
    var data = {}
    var url = 'api/member/self'
    var post = {
      info: data,
      url: url
    }
    var store = this.$store
    store.dispatch('post', post).then(res => {
      if (res.data.code == 1000) {
        //取紅包列表
        var memberData = res.data.data
        var data = {
          member_id: memberData.id,
          action: 1,
          page: 1,
          per_page: 20
        }
        var url = 'api/redEnvelope/list'
        var post = {
          info: data,
          url: url
        }
        store.dispatch('post', post).then(res => {
          if (res.data.code == 1000) {
            this.redEnvelList = res.data.data
          } else {
            alert(res.data.message)
          }
        })
      } else {
        alert(res.data.message)
      }
    })
  },
  methods: {}
}
</script>
<style scoped>
</style>

