<template>
<div>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('System_management')}} > {{$t('gameType_management')}}</h4>
            </div>
            <div class="row">
                <div class="pdd-horizon-30 pdd-vertical-20" style="text-align:center">    
                	<a href="javascript:void(0)" @click="editShow=true" data-toggle="modal" data-target="#edit_game_type" data-uid="0" class="btn btn-inverse btn-success edit">{{$t('gameType_add')}}</a>                            
                </div>
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>                                                        
                                            <th>{{$t('gameType_name')}}</th>                                                        
                                            <th>{{$t('status')}}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(type,i) in getGameType_List" :key="i">
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">{{type.name}}</span>
                                                    </div>
                                                </div>
                                            </td>                                                        
                                            <td>
                                                <div class="mrg-top-15">
                                                    <b class="text-dark font-size-16" :class="type.status !== 1 ? 'text-danger' : ''">{{type.status == 1 ? $t('enable') : $t('disable')}}</b>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button @click="toggleAction(type.id)" class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu" :class="{show: toggle == type.id}">
                                                            <li>
                                                                <a href="javascript:void(0)" @click="gameTypeEdit(type)" data-toggle="modal" data-target="#edit_game_type" data-uid="<?=$row['id']?>" class="edit">{{$t('Edit')}}</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="javascript:void(0)" @click="gameTypeDel(type)" class="user_toggle_status" :status="type.status !== 1 ? 1 : 0">{{type.status !== 1 ? $t('enable') : $t('disable')}}</a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
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
    <!-- 網域背景遮罩 -->
    <div class="modal-backdrop fade maskNone" :class="{show: editShow,showBlock: editShow}"></div>
    <!-- 編輯窗 -->
    <div class="modal fade" id="edit_game_type" :class="{show: editShow,showBlock: editShow}">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="game_type_content">
                <div id="data-form">
                    <div class="modal-body">
                        <div class="padding-15">
                            <div class="card-block">
                                <div class="pill-info nav-vertical nav-stacked">
                                    <ul class="nav nav-pills" role="tablist">
                                        <li class="nav-item">
                                            <a href="javascript:void(0)" @click="langActive = 0" class="nav-link" :class="{active: langActive == 0}" role="tab" data-toggle="tab">{{$t('default')}}</a>
                                        </li>
                                        <li v-for="(lang,i) in getLanguages" :key="i" class="nav-item">
                                            <a href="javascript:void(0)" @click="langActive=lang.id" class="nav-link" :class="{active: langActive == lang.id}" role="tab" data-toggle="tab">{{lang.name}}</a>
                                        </li>
                                    </ul>
                                    <div class="tab-content">
                                        <div role="tabpanel" class="tab-pane fade" :class="{in:langActive == 0,active: langActive == 0}"  id="default">
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('upper_layer_type')}}</p>
                                                <select v-model="type" class="form-control" name="parent">
                                                    <option :value="0">{{$t('peak')}}</option>
                                                    <option v-for="(type,i) in getGameType_List" :key="i" :value="type.id">
                                                        {{type.name}}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('gameType_name')}}</p>
                                                <input v-model="name" type="text" class="form-control" name="name">
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('status')}}</p>
                                                <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                                    <input v-model="status" type="checkbox" name="status" id="toggle1">
                                                    <label for="toggle1"></label>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-for="(lang,i) in getLanguages" :key="i" role="tabpanel" class="tab-pane fade" :class="{in:langActive == lang.id,active: langActive == lang.id}" >
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('gameType_name')}}</p>
                                                <input v-model="allLang[lang.id]" type="text" class="form-control" placeholder="Taiwan">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="id" value="<?= $data['id'] ?>">
                    <input type="hidden" name="action" value="save_game_type">
                </div>
                <div class="modal-footer no-border">
                    <div class="text-right">
                        <button @click="closeEdit()" class="btn btn-default btn-sm" data-dismiss="modal">{{$t('cancel')}}</button>
                        <button @click="saveGametype()" class="btn btn-primary btn-sm" data-dismiss="modal" id="save">{{$t('Save')}}</button>
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
        ...mapGetters(['getGameType_List','getLanguages','getGameType_View'])
    },
    watch: {
        getGameType_View(view) { //開啟編輯窗取到getGameType_View 帶入編輯input
            this.id = view.id
            this.type = view.parent
            this.name = view.name
            this.status = view.status
            if(view.names){
                view.names.forEach((e)=>{
                    this.allLang[e.language_id] = e.name
                })
            }
        }
    },
    data() {
        return {
            langActive: 0,
            toggle: null,
            editShow: false,

            id: null,
            type: 0,
            name: null,
            allLang: [],
            status: 0
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
        gameTypeEdit(type) {
            this.editShow = true
            this.$store.dispatch('action_gameType_view',type.id)
        },
        closeEdit(){ //關閉新增編輯窗
            this.editShow = false
            //clearGAME_TYPE_View 清空store.state的view資料，預防先編輯遊戲類別之後再新增遊戲類別，編輯遊戲類別的資料會帶入新增遊戲類別的input內(因為新增編輯窗是共用)
            this.$store.commit('clearGAME_TYPE_View')
        },
        saveGametype() { //儲存新增編輯
            let names = []
            this.allLang.forEach((e,key)=>{
                if(e && e!==''){
                    let item = {
                        "language_id":key,
                        "name":e
                    }
                    names.push(item)
                }
            })
            let data = {
                id: this.id, 
                name: this.name,
                names: names,
                parent: this.type,
                status: this.status
            }
            if(this.getGameType_View.name) { // 編輯
                // console.log('編輯',data)
                this.$store.dispatch('action_gameType_edit',data)
            } else { // 新增
                // console.log('新增',data)
                this.$store.dispatch('action_gameType_add',data)
            }
        },
        gameTypeDel(type) {
            // country凍結
            if(type.status == 1) { //狀態開啟時 走凍結路
                // console.log('凍結',type.id)
                this.$store.dispatch('action_gameType_delete', type.id)
            } else { //狀態凍結時 走開啟路
                // console.log('開啟',type.id)
                this.$store.dispatch('action_gameType_edit', {id:type.id,status:1})
            
            }
        }
    },
    created() {
        this.$store.dispatch('action_gameType_list')
        this.$store.dispatch('action_languages')
    },
}
</script>
<style scoped>
.showBlock {
    display: block !important
}
.maskNone {
    display: none
}
</style>