<template>
  <div>
    <div class="listTitleHeight">
      <listTitle />
    </div>
    <div class="allContent whiteAll">
      <div class="whiteCon">
        <div class="GroupPoint">點數:887,802.37</div>
        <div class="inputCon clearfix">
          總數：
          <input class placeholder="請輸入總數" v-model="Points" />
          <div class="closeInputBtn float-right">
            <img src="../assets/img/close.png" @click="resetPoints()" />
          </div>
        </div>
        <div class="groupRedBtn clearfix">
          <div class="gwiorBtn gwiorBtnBgGr float-left">普通紅包</div>
          <div class="gwiorBtn gwiorBtnBgOr float-right">隨機紅包</div>
        </div>
        <div class="inputCon clearfix">
          紅包數：
          <input class placeholder="請填寫個數" v-model="Points" />
          <div class="closeInputBtn float-right">
            <img src="../assets/img/close.png" @click="resetPoints()" />
          </div>
        </div>
        <div class="groupMember">本群組共3人</div>
        <div class="inputCon clearfix">
          備註：
          <input class placeholder="請輸入備註訊息" v-model="Remarks" />
          <!-- <b-form-textarea
            id="textarea"
            v-model="Remarks"
            placeholder="請輸入備註訊息..."
            rows="3"
            max-rows="6"
          ></b-form-textarea>-->
          <div class="closeInputBtn float-right">
            <img src="../assets/img/close.png" @click="resetRemarks()" />
          </div>
        </div>
        <div class="redEnvelopesBtnCon">
          <div class="redEnBtnTop">150.00</div>
          <div class="redEnBtnCen">未領取的紅包，將於24小時候退還</div>
          <div class="redEnBtnBot blueBtnPic buttonAll ContentFont">塞進紅包</div>
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
      Points: '',
      Remarks: ''
    }
  },
  methods: {
    resetPoints() {
      this.Points = ''
    },
    resetRemarks() {
      this.Remarks = ''
    }
  }
}
</script>
<style scoped>
</style>

