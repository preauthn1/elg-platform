<template>
<!-- 儲值 停用!!! 寫在header --> 
<div class="half_popUp" v-if="deposit_is_open">
    <div class="wrap">
        <img class="bg" src="/static/img/C711_PC/user@example.invalid">
        <div class="block">
            <div class="head">
                <p>儲值</p>
                <img @click="deposit_close" src="/static/img/C711_PC/Group5301.png"/>
            </div>
            <div class="body">
                <div class="content">
                    <p>v-world金額: <span>0.00</span></p>
                    <p>遊戲商: <span>AG</span></p>
                    <div class="ele-border">
                        <input type="text" class="ele_border_none dark-brown">
                    </div>
                </div>
                <div>
                    <div class="btn-blue"><button>註冊</button></div>
                </div>
            </div>
        </div>
    </div>
</div>
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getOrderTypes'])
    },
    data() {
        return {
            deposit_is_open: false,
            selected: null,
        }
    },
    methods: {
        deposit_close() {
        }
    },
    created() {
        this.$store.dispatch('actionOrderTypes')
        // this.$store.dispatch('actionMember')
    }
}
</script>