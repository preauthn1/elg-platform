<template>
 <div class="all">
     
    <div class="validate">
       <div class="validate__wrap">
            <el-form  label-width="100px">
                <div class="validate__title"><h2>您的帳戶驗證</h2></div>
                <div class="form__wrap">
                    <div class="form_font">
                        
                            依據規管要求,我們需要能夠準確識別客戶身分以確保帳戶安全.我們將此過程稱為"身分驗證過程(即KYC)" <br>KYC包括兩個步驟 您將需要分別完成步驟1及步驟2中的一項檢查<br>
                            在您成功完成KYC驗證之前,您將無法從您的帳戶中提款<br>
                            如果您選擇上傳或通過電子郵件發送文件的方式完成步驟1和步驟2,請確保您在每個步驟中使用不同文件
                    </div>
                </div>
                <div class="validate__title"><h2>上傳您的身分證明文件</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <div class="form_font">
                        
                            您可以透過以下選項上傳您的身分證明文件或其拍照:
                       </div>
                        
                        <div class="upbuttonB">
                            <el-row>
                                <el-col :span="24">
                                    <el-upload
                                        class="upload-demo"
                                        drag
                                        action="https://example.invalid/posts/"
                                        multiple>
                                        <i class="el-icon-upload"></i>
                                        <div class="el-upload__text">將文件拖到此處，或<em>點擊上傳</em></div>
                                        <div class="el-upload__tip" slot="tip">只能上傳JPG/PNG文件</div>
                                        </el-upload>

                                </el-col>
                            </el-row>
                            <el-row >
                                <el-col :span="8">
                                    <img :src="getID_F">
                                    <el-button v-if="$store.state.IDF_Status !== 1" @click="createID_F(1)">身分證正面</el-button>
                                        <span v-if="$store.state.IDF_Status == 1">通過<i class="el-icon-circle-check"></i></span>
                                        <span v-if="$store.state.IDF_Status == 2">未通過<i class="el-icon-circle-close"></i></span>
                                
                                </el-col>
                                <el-col :span="8">
                                    <img :src="getID_B">
                                       <el-button v-if="$store.state.IDB_Status !== 1" @click="createID_B(2)">身分證反面</el-button>
                                            <span v-if="$store.state.IDB_Status == 1" >通過<i class="el-icon-circle-check"></i></span>
                                            <span v-if="$store.state.IDB_Status == 2" >未通過<i class="el-icon-circle-close"></i></span>
                                        
                                </el-col>
                                <el-col :span="8">
                                    <img :src="getPort">
                                   <el-button v-if="$store.state.portStatus !== 1" @click="createPort(3)">護照</el-button>
                                        <span v-if="$store.state.portStatus == 1" >通過<i class="el-icon-circle-check"></i></span>
                                        <span v-if="$store.state.portStatus == 2" >未通過<i class="el-icon-circle-close"></i></span>
                                        <span v-else >未驗證<i class="el-icon-circle-close"></i></span>
                                   
                                </el-col>
                            </el-row>
                         </div>   
                            

                        
                        <div class="upbuttonS">
                            <el-row>
                                <el-col :span="24">
                                    <el-upload
                                        class="avatar-uploader"
                                        action="https://example.invalid/posts/"
                                        :show-file-list="false"
                                        :on-success="handleAvatarSuccess"
                                        :before-upload="beforeAvatarUpload">
                                        <img v-if="imageUrl" :src="imageUrl" class="avatar">
                                        <i v-else class="el-icon-plus avatar-uploader-icon"></i>
                                        </el-upload>

                                </el-col>
                            </el-row>
                        <el-row  >
                                <div style="margin-bottom:8px;">
                                    <img :src="getID_F">
                                    <el-button v-if="$store.state.IDF_Status !== 1" @click="createID_F(1)">身分證正面</el-button>
                                        <span v-if="$store.state.IDF_Status == 1">通過<i class="el-icon-circle-check"></i></span>
                                        <span v-if="$store.state.IDF_Status == 2">未通過<i class="el-icon-circle-close"></i></span>
                                   
                                </div>
                                <div style="margin-bottom:8px;">
                                    <img :src="getID_B">
                                       <el-button v-if="$store.state.IDB_Status !== 1" @click="createID_B(2)">身分證反面</el-button>
                                            <span v-if="$store.state.IDB_Status == 1" >通過<i class="el-icon-circle-check"></i></span>
                                            <span v-if="$store.state.IDB_Status == 2" >未通過<i class="el-icon-circle-close"></i></span>
                                        
                                </div>
                                <div style="margin-bottom:8px;">
                                    <img :src="getPort">
                                   <el-button v-if="$store.state.portStatus !== 1" @click="createPort(3)">護照</el-button>
                                        <span v-if="$store.state.portStatus == 1" >通過<i class="el-icon-circle-check"></i></span>
                                        <span v-if="$store.state.portStatus == 2" >未通過<i class="el-icon-circle-close"></i></span>
                                        <span v-else >未驗證<i class="el-icon-circle-close"></i></span>
                                   
                                </div>
                            </el-row>
                        </div>
                    </div>
                </div>
                
                <div class="validate__title"><h2>電子郵件支持文件</h2></div>
                <div class="form__wrap">
                    <div class="form__width">
                        <div class="form_font">
                        請注意:驗證護照 駕照或ID卡最快捷的方法是使用"上傳"您的身分證明文件功能<br>
                        然而,若您使用其他形式的文件或者"上傳您的身分證明文件"功能無法使用,您可將文件副本通過電子郵件發送至 user@example.invalid 該郵件中必須附上您的用戶名和四位數安全代碼<br>
                        請確保所發送的文件包含您的全明和出生日期,並與您bet365帳戶中註冊的一致 如適用,文件必須保證未過期,且相關對帳單或帳單上的日期必須最近三個月之內<br><br>
                        我們接受謝列文件類型: <br>       
                        居住許可<br>
                        簽證               </div>
                        
                        
                    </div>
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
            getID_F: 'getID_F',
            getID_B: 'getID_B',
            getPort: 'getPort',
        }),
    },
    methods: {
        ...mapActions([
            'actionID_F',
            'actionID_B',
            'actionPort',
            'createID_F',
            'createID_B',
            'createPort',
        ]),
    },
     data() {
      return {
        options: [{
          value: '選項1',
          label: '銀行對帳單'
        }, {
          value: '選項2',
          label: '市政府稅單'
        }, {
          value: '選項3',
          label: '信用卡對帳單'
        }, {
          value: '選項4',
          label: '座機或手機電話單'
        }, {
          value: '選項5',
          label: '水電煤氣費帳單'
        }],
        value: ''
      }
    }
}
</script>

<style lang="scss" scoped>
.all{
    padding-top:95px;
   width:100%;
}
.validate {
   width: 100%;
   
    .validate__wrap {
         
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        padding-bottom:20px;
        .validate__title {
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
        
        .validate__subTitle {
            color: #fff;
            text-align: center;
            background-color: #4d7992;
            margin: 0 30px;
            
        }
        .form__wrap{
            
            margin: 30px;
           padding:5px;
            background-color: #eee;
            text-align:center;
            color:#666;
        }
         .form_font{
            
            margin: 8px;
            
            text-align:left;
            color:#666;
            font-size:15px;
            line-height:30px;
            font-weight:bold;
           
            
        }
.upbuttonB{
    display:block;
}
@media screen and(max-width: 650px) {
    .upbuttonB{
    display:none;
}
}
.upbuttonS{
    display:none;
}
@media screen and(max-width: 650px) {
    .upbuttonS{
    display:block;
}
}
.avatar-uploader .el-upload {
    border: 1px dashed #d9d9d9;
    border-radius: 6px;
    cursor: pointer;
    position: relative;
    overflow: hidden;
  }
  .avatar-uploader .el-upload:hover {
    border-color: #409EFF;
  }
  .avatar-uploader-icon {
    font-size: 28px;
    color: #8c939d;
    width: 178px;
    height: 178px;
    line-height: 178px;
    text-align: center;
  }
  .avatar {
    width: 178px;
    height: 178px;
    display: block;
  }
         @media only screen and(max-width:650px){
		   .form__wrap{padding:10px 10px 0 10px;}
        } 
        .form__width {
            width: 100%;
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

//.acct {
//    width: 100%;
// //   .acct__wrap {
 //       width: 800px;
 //       margin: auto;
 //       background-color: #fff;
 //       font-size: 12px;
 //   }
 //   .el-icon-circle-close {
 //     color: red;
 //   }
}


</style>