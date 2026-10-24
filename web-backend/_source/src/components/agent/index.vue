<template>
<div>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('Agent_management')}} > {{$t('Agent_account_management')}}</h4>
            </div>
            <!-- <a href="edit.php" class="btn btn-inverse btn-success"><?=$lang['add_agent']?></a> -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="row">
                                <div class="col-md-7">
                                    <h4 class="card-title">{{$t('search')}}</h4>
                                    <div class="form-horizontal mrg-top-40 pdd-right-30">
                                        <div class="form-group row">
                                            <label for="form-1-1" class="col-md-2 control-label">{{$t('agent')}}</label>
                                            <div class="col-md-10">
                                                <select v-model="parent" class="form-control" id="selectize-dropdown">
                                                    <!-- <option value="" disabled selected>選擇全部或是第一層營運商</option> -->
                                                    <!-- <option value="-" <?=($data['parent'] == '-') ? 'selected' : ''?>>全部</option>
                                                    <option value="1" <?=($data['parent'] == '1') ? 'selected' : ''?>>第一層</option> -->
                                                    <option value="">{{$t('all')}}</option>
                                                    <option value="0">{{$t('level_one')}}</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="form-group row">
                                            <label for="form-1-2" class="col-md-2 control-label"></label>
                                            <div class="col-md-10">
                                                <button @click="searchAgent('search')" class="btn btn-primary search">{{$t('start_search')}}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{$t('username')}}</th>
                                            <th>{{$t('email')}}</th>
                                            <th>{{$t('phone')}}</th>
                                            <th>{{$t('status')}}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(agent,i) in getAgent_List" :key="i">
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">{{agent.username}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b>{{agent.email}}</b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>{{agent.mobile}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15" :class="agent.status !== 1 ? 'text-danger' : ''">
                                                   	{{agent.status == 1 ? $t('enable') : $t('disable')}}
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block" :class="{show : editShow == agent.id}">
                                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" @click="toggleAction(agent.id)">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu" :class="{show : editShow == agent.id}">
                                                            <li>
                                                                <a :href="'/agent/profile?id='+ agent.id">{{$t('view_details')}}</a>
                                                            </li>
                                                            <li>
                                                                <a href="javascript:void(0)" @click="openDomainList(agent)">{{$t('domain_setting')}}</a>
                                                            </li>
                                                            <li>
                                                                <!-- <a :href="'/agent/index?id='+ agent.id">查看下線</a> -->
                                                                <router-link :to="'/agent/index?id='+ agent.id">{{$t('view_downline')}}</router-link>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <!-- <a href="#" class="agent_toggle_status" id="<?=$row['id']?>" status="<?=($row['status'] != 1) ? 1 : 0?>"><?=($row['status'] != 1) ? '啟用' : '停用'?></a> -->
                                                                <a href="javascript:void(0)" @click="agentDel(agent)" class="user_toggle_status" :agent_id="agent.id" :status="agent.status !== 1 ? 1 : 0">{{agent.status !== 1 ? $t('enable') : $t('disable')}}</a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="Pagination" v-if="getAgent_Pagination.length !== 0 && getAgent_Pagination.total !== 0">
                                <div @click="getList(1)" v-if="getAgent_Pagination.previous !== ''">{{$t('firstPage')}}</div>
                                <div @click="getList(getAgent_Pagination.previous)" v-if="getAgent_Pagination.previous !== ''">{{$t('previousPage')}}</div>
                                <div @click="getList(item)" :class="{circle: item === currentPage}" class="page" v-for="(item, index) in pages"
                                    :key="index" v-if="item <= getAgent_Pagination.pages && item >= 1">{{item}}</div>
                                <div @click="getList(getAgent_Pagination.next)" v-if="getAgent_Pagination.next !== ''">{{$t('nextPage')}}</div>
                                <div @click="getList(getAgent_Pagination.pages)" v-if="getAgent_Pagination.next !== ''">{{$t('lastPage')}}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- 網域清單列表 -->
    <div class="modal fade" :class="{show: domainShow,showBlock: domainShow}" id="domain_list">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="domain_content">
                <div class="modal-body height-160">
                    <a class="modal-close" href="javascript:void(0)" data-dismiss="modal" style="float: right;" @click="domainShow = false">
                        <i class="ti-close"></i>
                    </a>
                    <div class="vertical-align text-center">
                        <div class="table-cell">
                            <div class="container">
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="card">
                                            <div class="card-block">
                                                <h4 class="card-title">{{$t('domain_list')}}</h4>
                                                <button @click="domainAddShow = true" id="add" data-uid="<?=$_POST['id']?>" data-toggle="modal" data-target="#domain_add" class="btn btn-sm btn-success btn-rounded">{{$t('add_domain')}}</button>
                                                <p>{{$t('status_can_managed&removed')}}</p>
                                                <div class="table-overflow">
                                                    <table class="table table-striped">
                                                        <thead>
                                                            <tr>
                                                                <th>{{$t('domain_name')}}</th>
                                                                <th>{{$t('status')}}</th>
                                                                <th></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr v-for="(domain,i) in getAgent_Domain_List" :key="i">
                                                                <td>{{domain.domain}}</td>
                                                                <td>
                                                                    <div @click="changeStatus(domain)" class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                                                        <input  type="checkbox" name="status" :status="domain.status == 1 ? 0 : 1" class="domain_toggle_status" :checked="domain.status==1">
                                                                        <label :for="domain.id"></label>
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    <a @click="domain_delete(domain.id)" href="javascript:void(0)" class="btn btn-danger btn-rounded btn-sm delete" data-id="<?=$domain['id']?>" >{{$t('del_domain')}}</a>
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
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
    <!-- 網域背景遮罩 -->
    <div class="modal-backdrop fade maskNone" :class="{show: domainShow,showBlock: domainShow}"></div>
    <!-- 新增網域 -->
    <div class="modal slide-in-right modal-right" :class="{showBlock: domainAddShow}" id="domain_add">
        <div class="modal-dialog" role="document">
            <div class="modal-content" id="domain_add_content">
                <div class="side-modal-wrapper">
                    <div class="vertical-align">
                        <div class="table-cell">
                            <div class="pdd-horizon-15">
                                <h4>{{$t('add_domain')}} - {{$t('please_enter_domainContent_below')}}</h4>
                                <div id="data-form">
                                    <div class="form-group">
                                        <p class="mrg-btm-15 font-size-13">{{$t('domain')}}</p>
                                        <input v-model="domain" type="text" class="form-control" placeholder="example.invalid" name="domain">
                                    </div>
                                    <div class="form-group">
                                        <p class="mrg-btm-15 font-size-13">{{$t('status')}}</p>
                                        <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                            <input type="checkbox" v-model="status" name="status" id="toggle1">
                                            <label for="toggle1"></label>
                                        </div>
                                    </div>
                                    <button class="btn btn-info btn-sm" @click="domain_add()">{{$t('Add')}}</button>
                                    <button class="btn btn-secondary btn-sm" @click="domainAddShow = false">{{$t('cancel')}}</button>
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
// import DomainList from './domain_list'
// import DomainAdd from './domain_add'
export default {
    // components: {
    //     DomainList,DomainAdd
    // },
    computed: {
        ...mapGetters(['getAgent_List','getAgent_Pagination','getAgent_Domain_List']),
        // 分頁
        pages() {
            const c = this.getAgent_Pagination.current
            const t = this.getAgent_Pagination.pages
            if (c <= 5) { //第一种情况
                return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] 
            } else if (c >= t - 4) { //第二种情况
                return [t - 9,t - 8,t - 7,t - 6,t - 5,t - 4,t - 3,t - 2,t - 1,t]
            } else { //第三种情况
                return [c - 4,c - 3,c - 2,c - 1, c,c + 1,c + 2,c + 3,c + 4,c + 5]
            }
        }
    },
    watch: {
        $route() {
            let id = this.$route.query.id
            if(id) {
                let data = {
                    parent: id, //搜尋父層id下線，故parent:id
                    page: 1,
                    per_page: 25
                }
                // console.log('查看下線',data)
                this.$store.dispatch('action_agent_list', data)
            } 
            else {
                let data = {
                    parent: this.parent,
                    page: 1,
                    per_page: 25
                }
                this.$store.dispatch('action_agent_list', data)
            }
        }
    },
    data() {
        return {
            currentPage: 1,
            parent: '',
            editShow: null,

            //domin .show
            domainShow: false,
            //domin add .show
            domainAddShow: false,
            //domain data
            agent_id: null,
            domain: '',
            status: 0
        }
    },
    methods: {
        searchAgent(type) {
            let data = {
                parent: this.parent,
                page:1,
                per_page:25
            }
            if(this.$route.query.id && type!== 'search') { //查詢下線router 存在query.id且不是手動搜尋
                data.parent = this.$route.query.id
                // console.log('agent',data)
                this.$store.dispatch('action_agent_list', data)
            } else { //手動查詢
                if(this.$route.query.id) { // 路游存在查看下線id時， '/agent/index?id=123'需跳回預設路游'/agent/index'(無下線id狀態)
                    this.$router.push('/agent/index')
                }
                // console.log('agent',data)
                this.$store.dispatch('action_agent_list', data)
            }  
        },
        // 分頁
        getList(page) {
            this.currentPage = page
            let data = {
                parent: this.parent,
                page: page,
                per_page: 25
            }
            if(this.$route.query.id) {
                data.parent = this.$route.query.id
                // console.log('data1',data)
                this.$store.dispatch('action_agent_list', data)
            } else {
                // console.log('data2',data)
                this.$store.dispatch('action_agent_list', data)
            }
            
        },
        toggleAction(id){
            if(this.editShow == id) {
                this.editShow = null
            } else {
                this.editShow = id
            }
        },
        // Domain list 開啟網域清單列
        openDomainList(agent) {
            this.domainShow = true
            this.agent_id = agent.id
            let data = {
                agent_id: agent.id,
                page:1,
                per_page:1000
            }
            this.$store.dispatch('action_agentDomain_list',data)
        },
        // Domain add 新增網域
        domain_add() {
            let data = {
                agent_id: this.agent_id,
                domain: this.domain,
                status: this.status
            }
            this.$store.dispatch('action_agentDomain_Add',data)
            this.domainAddShow = false
        },
        domain_delete(id) { //停用agentDomain
            let data = {
                agent_id: this.agent_id,
                id: id
            }
            this.$store.dispatch('action_agentDomain_Delete',data)
        },
        changeStatus(domain) { //變更agentDomain狀態開放或關閉
            let data = {
                agent_id: domain.agent_id,
                id: domain.id,
                domain: domain.domain
            }
            if(domain.status == 0){
                data['status'] = 1
                this.$store.dispatch('action_agentDomain_Edit',data)
            }else {
                data['status'] = 0
                this.$store.dispatch('action_agentDomain_Edit',data)
            }
        },
        // 凍結營運商
        agentDel(agent) {
            if(agent.status == 1) { //狀態開啟時 走凍結路
                if(agent.id == sessionStorage.getItem('user_id')) {
                    alert("You can't delete yourself")
                } else {
                    this.$store.dispatch('action_agent_delete',agent.id)
                    console.log('凍結',agent.id)
                }
            } else { //狀態凍結時 走開啟路
                if(agent.id == sessionStorage.getItem('user_id')) {
                    alert("You can't delete yourself")
                } else {
                    this.$store.dispatch('action_agent_edit',{id:agent.id,status:1})
                    console.log('開啟',agent.id)
                }
            }
        },
        
    },
    created() {
        this.searchAgent()
    },
}
</script>
<style scoped>
.Pagination {
    display: flex;
    justify-content: center;
}
.Pagination > div {
    padding: 5px;
    margin: 5px;
    cursor: pointer;
}
.circle {
    color: #ff3c7e;
    font-weight: 700;
}
@media screen and (max-width: 400px) {
    .Pagination > div {
        margin: 0;
    }
}
.showBlock {
    display: block !important
}
.maskNone {
    display: none
}
</style>