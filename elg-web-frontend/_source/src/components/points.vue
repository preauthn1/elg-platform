<template>
 <div class="all">
    <div class="points">
        <div class="points__wrap">
            <el-form label-width="100px">
                <div class="points__title"><h2>balance to vendor </h2></div>
                 <div class="points__title"><h3>平台金額: {{ $store.state.balance }}</h3></div>
                <div class="form__wrap">
                   <el-table :data="vendors" border style="width: 100%">
                    <el-table-column prop="name" label="帳戶" min-width="120px"></el-table-column>
                    <el-table-column prop="rate" label="比值"></el-table-column>
                    <el-table-column prop="balance" label="餘額"></el-table-column>
                    <el-table-column prop="withdrawal" label="可提款"></el-table-column>
                    <p>{{vendors}}</p>
                </el-table>
               <el-form-item label="轉帳到">
                    <el-select v-model="transferTo" size="mini">
                        <el-option v-for="item in vendors" :key=item.id :label=item.name :value=item.id></el-option>
                    </el-select>
                </el-form-item>
                <el-form-item label="點數">
                    <el-input v-model="point" size="mini"></el-input>
                </el-form-item>
                
                </div>
                <div class="button_b">               
                    <el-button @click="balanceToVendor" size="mini">轉入遊戲</el-button>
                    <el-button @click="vendorToBalance" size="mini">轉回平台</el-button>
                </div>
            </el-form>
        </div>
    </div>
    
    <div style="height:30px;"></div>
</div>
   
    
</template>
<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    data() {
        return {
            
        }
    },
    computed: {
        ...mapGetters({
            vendors: 'getVendors',
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
            balanceToVendor: 'balanceToVendor',
            vendorToBalance: 'vendorToBalance',
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
		.points {padding-top:60px;    
}
    }
@media only screen and(max-width:650px){
		.points {padding-top:65px;    
}
    }
.button_b{
    padding:30px 0;
    text-align:center;
 }
.points {
   padding-top:102px;
    .points__wrap {
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        .points__title {
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
            text-align:left;
        }
         @media only screen and(max-width:650px){
		   .form__wrap{padding:10px 10px 0 10px;}
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

