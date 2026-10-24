<template>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('Agent_management')}} > {{$t('Agent_account_management')}} > {{$t('account_details')}}</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="widget-profile-1 card">
                                <div class="profile border bottom">
                                    <!--<img class="mrg-top-30" src="../assets/images/others/img-10.jpg" alt="">-->
                                    <h4 class="mrg-top-20 no-mrg-btm text-semibold">{{getAgent_View.nickname}}</h4>
                                    <p>{{getAgent_View.username}}</p>
                                </div>
                                <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                                    <a href="javascript:history.back()" class="btn btn-success btn-inverse btn-rounded">{{$t('backPage')}}</a>
                                    <a :href="'/agent/edit?id='+getAgent_View.id" class="btn btn-danger btn-inverse btn-rounded">{{$t('edit_basic_information')}}</a>
                                </div>
                            </div>
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title">{{$t('function')}}</h4>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-10">
                                            <ul class="list-unstyled list-info">
                                                <li>
                                                    <span class="thumb-img pdd-top-10">
                                                        <i class="ti-user font-size-30"></i>
                                                    </span>
                                                    <div class="info">
                                                        <b class="text-dark font-size-16">{{$t('status')}} ({{$t('block_member_acct')}})</b>
                                                        <p>{{$t('account_closed,agent_cant_login_backend')}}</p>
                                                    </div>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="col-md-2 text-right">
                                            <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-15">
                                                <!-- <input type="checkbox" id="status" <?=($agent['status'] == 1) ? 'checked' : ''?>> -->
                                                <input @change="changeStatus()" v-model="status" type="checkbox" id="status" >
                                                <label for="status"></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
							<!-- <div id="chart"></div> -->
                            <Chart></Chart>
                        </div>
                        <div class="col-md-8">
                            <div class="card">
                                <div class="card-heading border bottom">
                                    <h4 class="card-title">{{$t('basic_information')}}</h4>
                                </div>
                                <div class="card-block">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('name')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">{{getAgent_View.nickname}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('username')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">{{getAgent_View.username}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('email')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">{{getAgent_View.email}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('country/city')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <!-- <p class="mrg-top-10"><?=$_SESSION['country'][$agent['country_id']] . ' / ' . $agent['city']?></p> -->
                                            <p class="mrg-top-10">{{ countryName+' / '+ getAgent_View.city}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('region/Postal_code')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">{{getAgent_View.city}} / {{getAgent_View.zip_code?getAgent_View.zip_code:''}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('address')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">{{getAgent_View.city}}{{getAgent_View.district?' '+getAgent_View.district:''}}{{getAgent_View.addrress?' '+getAgent_View.addrress:''}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('phone')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">{{getAgent_View.mobile?getAgent_View.mobile:''}}</p>
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <div class="col-md-3">
                                            <p class="mrg-top-10 text-dark"> <b>{{$t('status')}}</b></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mrg-top-10">
                                                <span class="status mrg-top-10" :class="{online: getAgent_View.status == 1,offline:getAgent_View.status == 0}"></span>
                                                <span class="mrg-left-10">{{getAgent_View.status == 1 ? $t('enable') : $t('disable')}}</span>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <Template></Template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
<script>
import { mapGetters } from 'vuex'
import Template from './template'
import Chart from './chart'
export default {
    components: {
        Template,
        Chart
    },
    computed: {
        ...mapGetters(['getAgent_View']),
    },
    watch: {
        getAgent_View(agent) { // 取國家名
            let country_id = agent.country_id
            let country = JSON.parse(sessionStorage.getItem('country_list'))
            let res = country.filter(ary=>ary.id == country_id)
            this.countryName = res[0].name

            if(agent.status==1){ // 狀態
                this.status = true
            }else {
                this.status = false
            }
        },
    },
    data() {
        return {
            status: null,
            countryName: '',
        }
    },
    methods:{
        changeStatus() { //營運商狀態開關
            let data = {
                id: this.$route.query.id,
                status: this.status
            }
            this.$store.dispatch('action_agent_edit',data)
        },
        View() {
            let id = this.$route.query.id
            if(id){
                this.$store.dispatch('action_agent_view',id)
                this.id = id
            }
        }
    },
    created() {
        this.View()
    }
}
</script>