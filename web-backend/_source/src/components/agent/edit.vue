<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('Agent_management')}} > {{!getAgent_View.username ? $t('add_agent') : $t('edit_agent')}}</h4>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="mrg-top-40">
                            <div class="row">
                                <div class="col-md-8 ml-auto mr-auto">
                                    <div id="data-form">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label><span v-if="!getAgent_View.username" style="color:red">*</span>{{$t('username')}}</label>
                                                    <!-- 編輯 -->
                                                    <!-- <input v-if="getUser_View.length !== 0" type="hidden" name="id" :value="getUser_View.id"> -->
                                                    <p v-if="getAgent_View.username"> {{getAgent_View.username}}</p>
                                                    <!-- 新增 -->
                                                    <input v-else v-model="account" type="text" placeholder="Account" name="username" class="form-control">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('nickname')}}</label>
                                                    <input v-model="nickname" type="text" placeholder="Enter nickname" class="form-control" name="nickname">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="!getAgent_View.email" style="color:red">*</span>{{$t('email')}}</label>
                                                    <input v-model="email" type="email" placeholder="Enter email" class="form-control" name="email">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="!getAgent_View.username" style="color:red">*</span>{{$t('password')}}</label>
                                                    <input v-model="password" type="password" placeholder="Password" class="form-control" name="password">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label><span v-if="!getAgent_View.username" style="color:red">*</span>{{$t('confirm_password')}}</label>
                                                    <input v-model="password_confirm" type="password" placeholder="Confirm Password" class="form-control" name="password_confirmation">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('country')}}</label>
                                                    <select v-model="country_id" id="selectize-dropdown" class="form-control" name="country_id">
                                                        
                                                        <!-- <option value="<?= $key ?>"
                                                            <?= ($agent['country_id'] == $key) ? 'selected' : '' ?>>
                                                            <?= $value ?></option> -->
                                                        <option v-for="(country,i) in country_list" :key="i" :selected="getAgent_View.country_id == country.id" :value="country.id">
                                                            {{country.name}}</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('city')}}</label>
                                                    <input v-model="city" type="text" placeholder="City/Zone" class="form-control"
                                                        name="city">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('address')}}</label>
                                                    <input v-model="address" type="text" placeholder="Address" class="form-control"
                                                        name="address">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('phone')}}</label>
                                                    <input v-model="mobile" type="text" placeholder="Mobile" class="form-control"
                                                        name="mobile">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('percentage')}}</label>
                                                    
                                                    <input v-model="percentage" :disabled="!getAgent_View && getAgent_View.parent!==1" type="text" placeholder="18" class="form-control" name="percentage">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('status')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="status" type="radio" name="status" value="1" id="rad1">
                                                            <label for="rad1">{{$t('enable')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="status" type="radio" name="status" value="0" id="rad2">
                                                            <label for="rad2">{{$t('disable')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_pc')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_pc" type="radio" name="has_pc" value="1" id="pc1">
                                                            <label for="pc1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_pc" type="radio" name="has_pc" value="0" id="pc2">
                                                            <label for="pc2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_mobile')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_mobile" type="radio" name="has_mobile" value="1" id="mob1">
                                                            <label for="mob1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_mobile" type="radio" name="has_mobile" value="0" id="mob2">
                                                            <label for="mob2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_chat')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_chat" type="radio" name="has_chat" value="1" id="chat1">
                                                            <label for="chat1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_chat" type="radio" name="has_chat" value="0" id="chat2">
                                                            <label for="chat2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_online_payment')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_online_payment" type="radio" name="has_online_payment" value="1" id="pay1">
                                                            <label for="pay1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_online_payment" type="radio" name="has_online_payment" value="0" id="pay2">
                                                            <label for="pay2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_app')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_app" type="radio" name="has_app" value="1" id="app1">
                                                            <label for="app1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="has_app" type="radio" name="has_app" value="0" id="app2">
                                                            <label for="app2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_frontend_register')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="frontend_register" type="radio" name="frontend_register" value="1" id="f1">
                                                            <label for="f1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="frontend_register" type="radio" name="frontend_register" value="0" id="f2">
                                                            <label for="f2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_transaction_all')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="transaction_all" type="radio" name="transaction_all" value="1" id="ta1">
                                                            <label for="ta1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="transaction_all" type="radio" name="transaction_all" value="0" id="ta2">
                                                            <label for="ta2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('has_agent_backend_transaction')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="agent_backend_transaction" type="radio" name="agent_backend_transaction" value="1" id="abt1">
                                                            <label for="abt1">{{$t('has_open')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="agent_backend_transaction" type="radio" name="agent_backend_transaction" value="0" id="abt2">
                                                            <label for="abt2">{{$t('has_close')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 col-xs-6">
                                                <div class="text-right mrg-top-5">
                                                    <input type="hidden" name="action" value="save_agent">
                                                    <input type="button" class="btn btn-default" :value="$t('backPage')"
                                                        onclick="history.back();" />
                                                    <button @click="agentSave()" type="submit" class="btn btn-primary"
                                                        id="save">{{$t('Save')}}</button>
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
        ...mapGetters(['getAgent_View']),
    },
    watch: {
        getAgent_View(e) {
            this.nickname = e.nickname,
            this.email = e.email,
            this.password = [REDACTED],
            this.password_confirm = e.password_confirmation,
            this.country_id = e.country_id,
            this.city = e.city,
            this.address = e.address,
            this.mobile = e.mobile,
            this.percentage = e.percentage,
            this.status = e.status,
            this.has_pc = e.has_pc,
            this.has_mobile = e.has_mobile,
            this.has_chat = e.has_chat,
            this.has_online_payment = e.has_online_payment,
            this.has_app = e.has_app,
            this.frontend_register = e.frontend_register,
            this.transaction_all = e.transaction_all,
            this.agent_backend_transaction = e.agent_backend_transaction
        },
        $route() {
            // 如果正在/agent/edit編輯頁，點擊menu跳建立營運商頁 清除store view的資料
            if(!this.$route.query.id){
                this.$store.commit('AGENT_VIEW_CLEAR')
            }
        },
    },
    data() {
        return {
            country_list: JSON.parse(sessionStorage.getItem('country_list')),
            //
            id: null,
            account: '',
            nickname: '',
            email: '',
            password: '',
            password_confirm: '',
            country_id: 4,
            city: '',
            address: '',
            mobile: null,
            percentage: null,
            status: 0,
            has_pc: 0,
            has_mobile: 0,
            has_chat: 0,
            has_online_payment: 0,
            has_app: 0,
            frontend_register: 0,
            transaction_all: 0,
            agent_backend_transaction: 0,
        }
    },
    methods: {
        View() { //如果編輯路由帶有id，則為編輯狀態，打'action_agent_view'取資料，否則為新增
            let id = this.$route.query.id
            if(id){
                this.$store.dispatch('action_agent_view',id)
                this.id = id
            }
        },
        agentSave() {
            let id = this.$route.query.id
            if(id) { //編輯
                let data = {
                    id: this.id, 
                    nickname: this.nickname, 
                    email: this.email, 
                    password: [REDACTED], 
                    password_confirmation: this.password_confirm, 
                    country_id: this.country_id,
                    city: this.city,
                    address: this.address,
                    mobile: this.mobile, 
                    percentage: this.percentage,
                    status: this.status,
                    has_pc: this.has_pc,
                    has_mobile: this.has_mobile,
                    has_chat: this.has_chat,
                    has_online_payment: this.has_online_payment,
                    has_app: this.has_app,
                    frontend_register: this.frontend_register,
                    transaction_all: this.transaction_all,
                    agent_backend_transaction: this.agent_backend_transaction,
                }
                console.log('編輯',data)
                this.$store.dispatch('action_agent_edit',data)
            } else { //新增
                let data = { 
                    username: this.account,
                    nickname: this.nickname, 
                    email: this.email, 
                    password: [REDACTED], 
                    password_confirmation: this.password_confirm, 
                    country_id: this.country_id,
                    city: this.city,
                    address: this.address,
                    mobile: this.mobile, 
                    percentage: this.percentage,
                    status: this.status,
                    has_pc: this.has_pc,
                    has_mobile: this.has_mobile,
                    has_chat: this.has_chat,
                    has_online_payment: this.has_online_payment,
                    has_app: this.has_app,
                    frontend_register: this.frontend_register,
                    transaction_all: this.transaction_all,
                    agent_backend_transaction: this.agent_backend_transaction, 
                }
                console.log('新增',data)
                this.$store.dispatch('action_agent_add',data)
            }
        }
    },
    created() {
        this.View()
    }
}
</script>