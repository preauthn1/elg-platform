<template>
  <div class="Transfer">
    <LoginHeader></LoginHeader>
    <div class="TransferItem">
      <div class="TransferTitle">{{ $t("transferOut") }}</div>
      <div class="pointTo">
        <span>4D Lottery</span>
        <img src="../../assets/img/v_world/toL.png" />
        <span>V_World</span>
      </div>
      <div class="pointAcc clearfix">
        <div class="float-left">{{ $t("points") }}</div>
        <div class="float-right">
          <span>30</span>
        </div>
      </div>
      <div class="pointBtn">
        <b-container class="bv-example-row">
          <b-row>
            <b-col>
              <button type="button" class="btn btn-primary">10</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">30</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">50</button>
            </b-col>
          </b-row>
          <b-row>
            <b-col>
              <button type="button" class="btn btn-primary">100</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">300</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">500</button>
            </b-col>
          </b-row>
          <b-row>
            <b-col>
              <button type="button" class="btn btn-primary">1000</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">3000</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">5000</button>
            </b-col>
          </b-row>
          <b-row>
            <b-col>
              <button type="button" class="btn btn-primary">10000</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-primary">30000</button>
            </b-col>
            <b-col>
              <button type="button" class="btn btn-danger">MAX</button>
            </b-col>
          </b-row>
        </b-container>
      </div>
      <div class="modal-footer pointSubmit">
        <button
          @click="transfer()"
          type="button"
          class="btn btn-success"
          data-toggle="modal"
          data-target="#submitModal"
        >{{ $t("submit") }}</button>
        <div
          class="modal fade diglogMsg"
          id="submitModal"
          tabindex="-1"
          role="dialog"
          aria-labelledby="submitModalLabel"
          aria-hidden="true"
        >
          <div class="modal-dialog" role="document">
            <div class="modal-content">
              <div class="modal-body">918KISS限制15秒後才可再次轉出</div>
              <div class="modal-footer">
                <button
                  type="button"
                  class="btn btn-secondary"
                  data-dismiss="modal"
                >{{ $t("close") }}</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
export default {
  components: {
    LoginHeader
  },
  methods: {
    //轉點
    transfer() {
      //transfer error
      $('#submitModal').modal('show')
    }
  }
}
</script>
<!-- Add "scoped" attribute to limit CSS to this component only -->
<style  scoped>
</style>
