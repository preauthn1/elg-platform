<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('backend_management')}} > {{$t('account_management')}} > {{getUser_View.length == 0 ? $t('add_user') : $t('edit_user')}}</h4>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="mrg-top-40">
                            <div class="row">
                                <div class="col-md-8 ml-auto mr-auto">
                                    <div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="getUser_View.length == 0" style="color:red">*</span>{{$t('username')}}</label>
                                                    <!-- 編輯 -->
                                                    <!-- <input v-if="getUser_View.length !== 0" type="hidden" name="id" :value="getUser_View.id"> -->
                                                    <p v-if="getUser_View.length !== 0"> {{getUser_View.username}}</p>
                                                    <!-- 新增 -->
                                                    <input v-else v-model="account" type="text" placeholder="Account" name="username" class="form-control">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>{{$t('nickname')}}</label>
                                                    <input v-model="nickname" type="text" placeholder="Enter nickname" class="form-control" name="nickname">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="getUser_View.length == 0" style="color:red">*</span>{{$t('email')}}</label>
                                                    <input v-model="email" type="email" placeholder="Enter email" class="form-control" name="email">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="getUser_View.length == 0" style="color:red">*</span>{{$t('password')}}</label>
                                                    <input v-model="password" type="password" placeholder="Password" class="form-control" name="password">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="getUser_View.length == 0" style="color:red">*</span>{{$t('confirm_password')}}</label>
                                                    <input v-model="password_confirm" type="password" placeholder="Confirm Password" class="form-control" name="password_confirmation">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>{{$t('phone')}}</label>
                                                    <input v-model="mobile" type="text" placeholder="Mobile" class="form-control" name="mobile">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>{{$t('status')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="status" type="radio" name="status" value="1" id="rad1">
                                                            <label for="rad1">{{$t('enable')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="status"  type="radio" name="status" value="0" id="rad2">
                                                            <label for="rad2">{{$t('disable')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 col-xs-6">
                                                <div class="text-right mrg-top-5">
                                                    <input type="hidden" name="action" value="save_user">
                                                    <input type="button" class="btn btn-default" :value="$t('backPage')" onclick="history.back();" />
                                                    <button class="btn btn-primary" @click="userSave()">{{$t('Save')}}</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</template>
<script>
import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getUser_View']),
    },
    watch: {
        getUser_View(e) {
            this.nickname = e.nickname
            this.email = e.email
            this.mobile = e.mobile
        }
    },
    data() {
        return {
            id: null,
            account: '',
            nickname: '',
            email: '',
            password: '',
            password_confirm: '',
            mobile: null,
            status: 0,
        }
    },
    methods: {
        // user儲存
        userSave() {
            let id = this.$route.query.id
            if(id) { // 編輯
                let data = {
                    id: this.id, 
                    nickname: this.nickname, 
                    email: this.email, 
                    password: [REDACTED], 
                    password_confirmation: this.password_confirm, 
                    mobile: this.mobile, 
                    status: this.status, 
                }
                // console.log('編輯',data)
                this.$store.dispatch('action_user_edit',data)
            } else { // 新增
                let data = {
                    username: this.account, 
                    nickname: this.nickname, 
                    email: this.email, 
                    password: [REDACTED], 
                    password_confirmation: this.password_confirm, 
                    mobile: this.mobile, 
                    status: this.status, 
                }
                // console.log('新增',data)
                this.$store.dispatch('action_user_add',data)
            }
        },
        // 編輯View
        View() {
            let id = this.$route.query.id
            if(id){
                this.$store.dispatch('action_user_view',id)
                this.id = id
            }
        }
    },
    created() {
        this.View()
    }
}
</script>