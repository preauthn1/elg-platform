<template>
<div>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('backend_management')}} > {{$t('account_management')}}</h4>
            </div>
            <router-link to="/user/edit" class="btn btn-inverse btn-success">{{$t('add_user')}}</router-link>
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
                                        <tr v-for="(user,i) in getUser_List" :key="i">
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <img class="thumb-img" src="assets/images/avatars/thumb-4.jpg" alt="">
                                                    <div class="info">
                                                        <span class="title">{{user.username}}</span>
<!--                                                         <span class="sub-title">ID 861</span> -->
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info"><b>{{user.email}}</b></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span>{{user.mobile}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15" :class="user.status !== 1 ? 'text-danger' : ''">
                                                   	{{user.status == 1 ? $t('enable') : $t('disable')}}
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block" :class="{show : editShow == user.id}">
                                                        <button class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown" @click="toggleAction(user.id)">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu" :class="{show : editShow == user.id}">
                                                            <li>
                                                                <a :href="'/user/edit?id='+ user.id" @click="editShow= null">{{$t('Edit')}}</a>
                                                            </li>
                                                            <!-- <li>
                                                                <a href="javascript:void(0)" data-toggle="modal" data-target="#promission_modal" data-uid="<?=$row['id']?>" class="open_promission">{{$t('permission_setting')}}</a>
                                                            </li> -->
                                                            <li class="divider"></li>
                                                            <li>
                                                                <!-- <a href="#" class="user_toggle_status" user_id="<?=$row['id']?>" status="<?=($row['status'] != 1) ? 1 : 0?>"><?=($row['status'] != 1) ? '啟用' : '停用'?></a> -->
                                                                <a href="javascript:void(0)" @click="userDel(user)" class="user_toggle_status" :user_id="user.id" :status="user.status !== 1 ? 1 : 0">{{user.status !== 1 ? $t('enable') : $t('disable')}}</a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="Pagination" v-if="getUserPagination.length !== 0 && getUserPagination.total !== 0">
                                <div @click="getList(1)" v-if="getUserPagination.previous !== ''">{{$t('firstPage')}}</div>
                                <div @click="getList(getUserPagination.previous)" v-if="getUserPagination.previous !== ''">{{$t('previousPage')}}</div>
                                <div @click="getList(item)" :class="{circle: item === currentPage}" class="page" v-for="(item, index) in pages"
                                    :key="index" v-if="item <= getUserPagination.pages && item >= 1">{{item}}</div>
                                <div @click="getList(getUserPagination.next)" v-if="getUserPagination.next !== ''">{{$t('nextPage')}}</div>
                                <div @click="getList(getUserPagination.pages)" v-if="getUserPagination.next !== ''">{{$t('lastPage')}}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="promission_modal" :class="{show : false}">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-block">
                                    <h4 class="card-title">功能清單</h4>
                                    <p>可於下方清單管理啟用狀態及移除</p>
                                    <div class="table-overflow" id="promission">
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
import $ from '@/../static/js/jquery-3.4.1.min.js'
// import '../../assets/js/table/data-table'
// import '@/assets/vendors/datatables/media/js/jquery.dataTables'
export default {
    computed: {
        ...mapGetters(['getUser_List','getUserPagination']),
        // 分頁
        pages() {
            const c = this.getUserPagination.current
            const t = this.getUserPagination.pages
            if (c <= 5) { //第一种情况
                return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10] 
            } else if (c >= t - 4) { //第二种情况
                return [t - 9,t - 8,t - 7,t - 6,t - 5,t - 4,t - 3,t - 2,t - 1,t]
            } else { //第三种情况
                return [c - 4,c - 3,c - 2,c - 1, c,c + 1,c + 2,c + 3,c + 4,c + 5]
            }
        }
    },
    data() {
        return {
            currentPage: 1,
            editShow: null
        }
    },
    methods: {
        toggleAction(id){
            if(this.editShow == id) {
                this.editShow = null
            } else {
                this.editShow = id
            }
        },
        // user凍結
        userDel(user) {
            if(user.status == 1) { //狀態開啟時 走凍結路
                if(user.id == sessionStorage.getItem('user_id')){
                    alert("You can't delete yourself")
                } else {
                    // console.log('凍結',user.id)
                    this.editShow= null
                    this.$store.dispatch('action_user_delete', user.id)
                }
            } else { //狀態凍結時 走開啟路
                if(user.id == sessionStorage.getItem('user_id')){
                    alert("You can't delete yourself")
                } else {
                    // console.log('開啟',user.id)
                    this.editShow= null
                    this.$store.dispatch('action_user_edit', {id:user.id,status:1})
                }
            }
        },
        // 分頁
        getList(page) {
            this.currentPage = page
            let vid = this.$route.query.vid
            let data = {
                page: page,
                per_page: 25
            }
            this.$store.dispatch('action_user_list', data)
        },
    },
    created() {
        this.$store.dispatch('action_user_list', {page:1, per_page:25})
    }
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
</style>