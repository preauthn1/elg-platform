<template>
 <div class="all">
    <div class="deposit">
        <div class="deposit__wrap">
            <el-form label-width="100px">
                <div class="deposit__title"><h2>轉點</h2></div>
                <div class="deposit__title"><h3>平台金額: {{ $store.state.balance }}</h3></div>
                 <div class="deposit__title"><h3>遊戲商: {{ transferTo }} 餘額: {{ $store.state.balanceForm.vendorbalance }}</h3></div>
                <div class="form__wrap">
                    <el-input v-model="point" size="mini" ></el-input>                                  
                </div>
                <div class="button_b">               
                     <el-button @click="actionDeposit" size="mini">轉入遊戲</el-button>
                </div>
            </el-form>
        </div>
    </div>    
</div>
   
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    computed: {
        ...mapGetters({
        }),
        transferTo: {
            get () {
                return this.$store.state.balanceForm.transferTo
            },
            set (val) {
                this.$store.commit('updateTransferTo',val)
            }
        },
        point: {
            get () {
                return this.$store.state.balanceForm.point
            },
            set (val) {
                this.$store.commit('updatePoint',val)
            }
        }
    },
    methods: {
        ...mapActions({
            actionDeposit: 'actionDeposit',
        })
    },
    created() {
        this.$store.dispatch('getVendors')
        this.$store.dispatch('getAGbalance')
        this.$store.dispatch('getJokerbalance')
        this.$store.dispatch('getSCRbalance')
        this.$store.dispatch('getGDbalance')
    }
}
</script>
<style lang="scss" scoped>
.all{
    width: 100%;
    height:auto;
    padding-top:50px;
}
@media only screen and(max-width:768px){
		.all{padding-top:30px;    
}
    }
@media only screen and(max-width:650px){
		.all{padding-top:40px;    
}
	}
@media only screen and(max-width:768px){
		.deposit {padding-top:60px;    
}
    }
@media only screen and(max-width:650px){
		.deposit {padding-top:65px;    
}
    }
.button_b{
    padding:30px 0;
    text-align:center;
 }
.deposit {
   padding-top:102px;
   margin-bottom:425px;
   @media screen and (max-width: 650px) {
       margin-bottom:100px;
   }
    .deposit__wrap {
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        .deposit__title {
            color: #fff;
            padding: 5px 0;
            h2 {
                background-color: #4d7992;
                padding: 8px 30px;
                font-size: 18px;
                transform: translateX(30px)
            }
            h3{
               font-size: 18px;
                color:#666;
                transform: translateX(30px)
            }
        }        
        .form__wrap{
            margin: 0 20px;
            padding:5px;
            background-color: #eee;
            text-align:center;
        }
         @media only screen and(max-width:650px){
		   .form__wrap{padding:10px;}
        } 
        .form__width {
            width: 60%;
        }
        @media only screen and(max-width:768px){
		.form__width {
            width: 90%;
        }   
}
        @media only screen and(max-width:650px){
		   .form__width {
            width: 100%;
          } 
        }              
    }
}
</style>

