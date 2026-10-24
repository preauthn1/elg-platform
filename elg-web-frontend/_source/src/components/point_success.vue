<template>

    
        <div class="all">
            <div class="point_success">
        <div class="point_success__wrap">
            <el-form :model="ruleForm" :rules="rules" ref="ruleForm" label-width="100px">
               
                 <div class="point_success__title"><h3>儲值成功</h3></div>
                <div class="form__wrap">
                   <el-row>
                       <el-col :span="10">平台餘額:</el-col>
                       <el-col :span="14"><span>{{$store.state.balance}}</span></el-col>
                   </el-row>
                   <el-row>
                       <el-col :span="24"><h5>5 秒後將自動回首頁</h5></el-col>
                       
                   </el-row>
               
                
                </div>
               
            </el-form>
        </div>
    </div>
    </div>
    

</template>
<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    methods: {
        ...mapActions({
            getMember: 'getMember',
        })
    },
    created() {
        this.$store.dispatch('pointSuccess')
    }
}
</script>
<style lang="scss" scoped>



.all{
    width: 60%;
    height:auto;
    max-height:100%;
    padding-top:50px;
    text-align:center;
    justify-content: center;
    margin:0 auto;

}



.point_success {
   padding-top:102px;
   
   width:100%;
   text-align:center;
   margin:0 auto;
   margin-bottom:510px;
    .point_success__wrap {
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        padding-bottom:30px;
        .point_success__title {
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
            h5{
                font-size:20px;
            }
        }
  
        .point_success__subTitle {
            color: #fff;
            text-align: center;
            background-color: #4d7992;
            margin: 0 30px;
            
        }
        .form__wrap{
            
            margin: 0 20px;
           padding:20px;
            background-color: #eee;
            text-align:center;
            color:#666;
            
        }
         @media only screen and(max-width:650px){
		   .form__wrap{padding:10px 10px 0 10px;}
        } 
        
     
       
    }
}
</style>
