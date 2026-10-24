<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('game_vendor_management')}} > {{$t('vendors_management')}} > {{$t('games_management')}}</h4>
        </div>
        <div class="row">
            <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">
                <a href="javascript:history.back()" class="btn btn-success btn-inverse btn-rounded">{{$t('backPage')}}</a>
                <a href="/game/edit" class="btn btn-danger btn-inverse btn-rounded">{{$t('game_add')}}</a>
            </div>
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="search_block">
                            <span>{{$t('search')}}:</span>
                            <input v-model="searchKey" type="text">
                        </div>
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th>{{$t('vendor_name')}}</th>
                                        <th>{{$t('game_code')}}</th>
                                        <th>{{$t('game_type')}}</th>
                                        <th>{{$t('game_name')}}</th>
                                        <th>{{$t('image')}}</th>
                                        <th>{{$t('status')}}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(games,i) in gamesData" :key="i">
                                        <td>
                                            <div class="list-info mrg-top-10">
                                                <div class="info">
                                                    <span class="title">{{games.vendor_name}}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="list-info mrg-top-10">
                                                <div class="info">
                                                    <span class="title">{{games.game_type}}</span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="relative mrg-top-15">
                                                <span class="pdd-left-20">{{games.type_name}}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b>{{games.name}}</b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span v-if="games.image">
                                                    <img class="thumb-img" :src="games.image" style="max-height:100px">
                                                </span>
                                                <span v-else>{{$t('not_uploaded')}}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15" :class="games.status !== 1 ? 'text-danger' : ''">
                                               {{games.status == 1 ? $t('enable') : $t('disable')}}
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-10 text-center">
                                                <div class="dropdown inline-block">
                                                    <button @click="toggleAction(games.id)" class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                        <i class="ti-angle-down font-size-9"></i>
                                                    </button>
                                                    <ul class="dropdown-menu" :class="{show: toggle == games.id}">
                                                        <li>
                                                            <a :href="'/game/edit?id='+games.id">{{$t('Edit')}}</a>
                                                        </li>
                                                        <li class="divider"></li>
                                                        <li>
                                                            <a href="javascript:void(0)" @click="toggleStatus(games)" class="game_toggle_status" :status="games.status !== 1 ? 1 : 0 ">{{games.status !== 1 ? $t('enable') : $t('disable') }}</a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="Pagination" v-if="getGamePagination.length !== 0 && getGamePagination.total !== 0">
                            <div @click="getList(1)" v-if="getGamePagination.previous !== ''">{{$t('firstPage')}}</div>
                            <div @click="getList(getGamePagination.previous)" v-if="getGamePagination.previous !== ''">{{$t('previousPage')}}</div>
                            <!-- <div class="disable" v-if="getGamePagination.previous == ''"></div> -->
                            <div @click="getList(item)" :class="{circle: item === currentPage}" class="page" v-for="(item, index) in pages"
                                :key="index" v-if="item <= getGamePagination.pages && item >= 1">{{item}}</div>
                            <!-- <div class="disable" v-if="getGamePagination.next == ''"></div> -->
                            <div @click="getList(getGamePagination.next)" v-if="getGamePagination.next !== ''">{{$t('nextPage')}}</div>
                            <div @click="getList(getGamePagination.pages)" v-if="getGamePagination.next !== ''">{{$t('lastPage')}}</div>
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
        ...mapGetters(['getVendor_List','getGameType_List','getGame_List','getGamePagination']),
        gamesData() {
            // vuex深拷貝用法，let data = JSON.parse(JSON.stringify(this.getGame_List))
            let data = JSON.parse(JSON.stringify(this.getGame_List))
            // getGame_List內沒有vendor與type的名稱只有id，故必須個別去getVendor_List 和 getGameType_List 撈出名子
            data.forEach(element => {
                let v = this.getVendor_List.filter((vendor)=>{
                    return vendor.id == element.vendor_id
                })
                element.vendor_name = v.length > 0 ? v[0].name : ''
                let t = this.getGameType_List.filter((type)=>{
                    return type.id == element.type_id
                })
                element.type_name = t.length > 0 ? t[0].name : ''
            });
            return data
        },
        // 分頁
        pages() {
            const c = this.getGamePagination.current
            const t = this.getGamePagination.pages
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
        $route(){ //router.vid為廠商管理內使用功能選單的'遊戲管理'進入遊戲管理會帶上vid
            let vid = this.$route.query.vid
            if(vid) {
                this.$store.dispatch('action_game_list',{vendor_id:vid,page:1,per_page:25})
            }else {
                this.$store.dispatch('action_game_list',{page:1,per_page:25})
            }
        },
        searchKey() { //及時搜尋
            clearTimeout(this.timeout)
            this.timeout = setTimeout(() => {
                console.log('searching')
                this.getList(1)
            }, 1000)
        }
    },
    data() {
        return {
            searchKey: '',
            currentPage: 1,
            toggle: null,
        }
    },
    methods: {
        toggleAction(id){
            if(this.toggle == id) {
                this.toggle = null
            } else {
                this.toggle = id
            }
        },
        toggleStatus(games) {
            if(games.status == 1) { //狀態開啟時 走凍結路
                // console.log('凍結',games.id)
                this.$store.dispatch('action_game_delete', games.id)
            } else { //狀態凍結時 走開啟路
                // console.log('開啟',games.id)
                this.$store.dispatch('action_game_edit', {id:games.id,status:1})
            }
        },
        gameView(vid) { //重新整理撈資料
            if(vid){
                this.$store.dispatch('action_game_list',{vendor_id:vid,page:1,per_page:25})
            }else {
                this.$store.dispatch('action_game_list',{page:1,per_page:25})
            }
        },
        // 分頁
        getList(page) {
            this.currentPage = page
            let vid = this.$route.query.vid
            let data = {
                name: this.searchKey,
                page: page,
                per_page: 25
            }
            if(vid) {
                data['vendor_id'] = vid
                this.$store.dispatch('action_game_list',data)
            }else {
                this.$store.dispatch('action_game_list',data)
            }
        },
    },
    created() {
        this.$store.dispatch('action_vendor_list')
        this.$store.dispatch('action_gameType_list',"")
        this.gameView(this.$route.query.vid)
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
.search_block {
    padding-bottom: 5px; 
}
.search_block input {
    border: 1px solid rgba(0,0,0,.2);
}
</style>