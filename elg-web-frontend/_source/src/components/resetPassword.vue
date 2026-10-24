<template>
    <div class="resetPassword">
        <el-col :span="24">
        <h1>重設密碼</h1>
       <!-- {{$route.params.token}} <br>
        {{$route.query.id}} <br>
        {{$route.path}} <br>-->
        </el-col>
        <el-form :model="form" :rules="rules" ref="form" label-width="100px">
            <el-form-item label="新密碼" prop="password">
                <el-col :span="15">
                 <el-input v-model="form.password" size="mini" width="10px"></el-input>
                </el-col>
            </el-form-item>
            <el-form-item label="確認新密碼" prop="checkpass">
                <el-col :span="15">
                    <el-input v-model="form.checkpass" size="mini"></el-input>
                </el-col>
            </el-form-item>
            <el-col :span="24">
                <el-button size="small" @click="newPass()">確認</el-button>
            </el-col>
        </el-form>
        
        <div class="bottom"></div>
    </div>
    
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    data() {
        var validatePassword = [REDACTED], value, callback) => {
            if (value === '') {
                callback(new Error('請輸入新密碼'))
            } else {
                if (this.form.checkpass !== '') {
                    this.$refs.form.validateField('checkpass')
                }
                callback();
            }
        }
        var validateCheck = (rule, value, callback) => {
            if (value === '') {
                callback(new Error('请再次输入密码'));
            } else if (value !== this.form.password) {
                callback(new Error('两次输入密码不一致!'));
            } else {
                callback();
            }
        }
        return {
            form: {
                password: '',
                checkpass: '',
            },
            rules: {
                password: [
                    { validator: validatePassword, trigger: 'blur'}
                ],
                checkpass: [
                    { validator: validateCheck, trigger: 'blur'}
                ]
            },
            resetToken: '',
        }
    },
    methods: {
        getToken() {
            this.resetToken = [REDACTED]
            this.$store.dispatch('actionResetToken', this.resetToken)
        },
        newPass() {
            let pass = this.form.password
            let checkpass = this.form.checkpass
            let resetToken = [REDACTED]
            this.$store.dispatch('actionResetPass', {pass:pass,checkpass:checkpass,resetToken:[REDACTED]
        }
    },
    created() {
        this.getToken()
    }
}
</script>
<style lang="scss" scoped>
.resetPassword {
    
    text-align: center;
    width: 100%;
    padding-top:108px;
    .el-form {
        width: 460px;
        margin: auto;
        /deep/ .el-form-item__label {
            color: #fff;
        }
    }
.bottom{
    padding-bottom:374px;
}
}
</style>

