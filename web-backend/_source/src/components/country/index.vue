<template>
<div>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('City_management')}} > {{$t('Country/city_management')}} > {{$t('Country_management')}}</h4>
            </div>
            <a href="javascript:void(0)" @click="isShow=true" data-toggle="modal" data-target="#edit_country" data-uid="0" class="btn btn-inverse btn-success edit">{{$t('country_add')}}</a>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{$t('name')}}</th>
                                            <th>{{$t('code')}}</th>
                                            <th>{{$t('status')}}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(country,i) in getCountry" :key="i">
                                            <td>
                                                <div class="list-info mrg-top-10">

                                                    <div class="info">
                                                        <span class="title">{{country.name}}</span>
                                                        <span class="sub-title">ID {{country.id}}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">{{country.code}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <b class="text-dark font-size-16" :class="country.status !== 1 ? 'text-danger' : ''">
                                                        {{country.status == 1 ? $t('enable') : $t('disable')}}</b>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button @click="toggleAction(country.id)" class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu" :class="{show : editShow == country.id}">
                                                            <li>
                                                                <a href="javascript:void(0)" @click="showEdit(country)" data-toggle="modal" data-target="#edit_country" class="edit">{{$t('Edit')}}</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="javascript:void(0)" @click="countryDel(country)" class="country_toggle_status" :status="country.status !== 1 ? 1 : 0">{{country.status !== 1 ? $t('enable') : $t('disable')}}</a>
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
    <div class="modal-backdrop fade maskNone" :class="{show: isShow,showBlock: isShow}"></div>
    <!-- 新增編輯窗(共用) -->
    <div class="modal fade" :class="{show: isShow,showBlock: isShow}" id="edit_country">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content" id="country_content">
                <div id="data-form">
                    <div class="modal-body">
                        <div class="padding-15">
                            <div class="card-block">
                                <div class="pill-info nav-vertical nav-stacked">
                                    <ul class="nav nav-pills" role="tablist">
                                        <li class="nav-item">
                                            <a href="javascript:void(0)" class="nav-link" @click="langActive = 0" :class="{active: langActive == 0}" role="tab" data-toggle="tab">{{$t('default')}}</a>
                                        </li>
                                        <li v-for="(lang,i) in getLanguages" :key="i" class="nav-item">
                                            <a href="javascript:void(0)" @click="langActive=lang.id" class="nav-link" :class="{active: langActive == lang.id}" role="tab" data-toggle="tab">{{lang.name}}</a>
                                        </li>
                                    </ul>
                                    <div class="tab-content">
                                        <div role="tabpanel" class="tab-pane fade" :class="{in:langActive == 0,active: langActive == 0}" id="default">
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('country_name')}}</p>
                                                <input v-model="name" type="text" class="form-control" placeholder="Taiwan" name="name">
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('country_shortName')}}</p>
                                                <input v-model="code" type="text" class="form-control" placeholder="TW" name="code">
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('country_code')}}</p>
                                                <input v-model="country_code" type="text" class="form-control" placeholder="886" name="country_code">
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('phone_regular')}}</p>
                                                <input v-model="phone_rule" type="text" class="form-control" placeholder="^[1-9]\d{7}$" name="phone_rule">
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
                                                <p class="mrg-btm-15 font-size-13">{{$t('country_name')}}</p>
                                                <input v-model="allLang[lang.id]" type="text" class="form-control" placeholder="Taiwan">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer no-border">
                    <div class="text-right">
                        <button class="btn btn-default btn-sm" @click="closeEdit()" data-dismiss="modal">{{$t('cancel')}}</button>
                        <button class="btn btn-primary btn-sm" @click="saveCountry()" data-dismiss="modal" id="save">{{$t('Save')}}</button>
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
        ...mapGetters(['getCountry','getCountry_View','getLanguages'])
    },
    watch: {
        getCountry_View(country) { //開啟編輯窗取到getCountry_View 帶入編輯input
            this.id = country.id
            this.name = country.name
            this.code = country.code
            this.country_code = country.country_code
            this.phone_rule = country.phone_rule
            this.status = country.status
            if(country.names){
                country.names.forEach((e)=>{
                    this.allLang[e.language_id] = e.name
                })
            }
        }
    },
    data() {
        return {
            langActive: 0,
            editShow: null,
            isShow: false,
            // 資料
            id: null,
            name: null,
            allLang: [], //多語系名稱
            code: null,
            country_code:null,
            phone_rule:null,
            status: false
        }
    },
    methods: {
        toggleAction(id){ //功能窗選單
            if(this.editShow == id) {
                this.editShow = null
            } else {
                this.editShow = id
            }
        },
        showEdit(country){ //開啟編輯彈窗，打'action_country_view'取要編輯的國家資料
            this.isShow = true
            this.$store.dispatch('action_country_view',country.id)
        },
        closeEdit(){ //關閉新增編輯彈窗
            this.isShow = false
            this.langActive = 0
            this.allLang = [] // 多語系名稱input清空，預防如下
            //clearCountry_View 清空store.state的view資料，預防先編輯國家之後再新增國家，編輯國家的資料會帶入新增國家的input內(因為新增編輯窗是共用)
            this.$store.commit('clearCountry_View') 
        },
        saveCountry(){
            let names = [] 
            this.allLang.forEach((e,key)=>{ //1.把allLang資訊ex:["english",null,"中文","簡中"]內容與key組成新物件item
                if(e && e!==''){
                    let item = {
                        "language_id":key,
                        "name":e
                    }
                    names.push(item) //2.各語系新物件item組成新陣列 for新增編輯的names(多語系名稱)
                }
            })
            let data = {
                id: this.id,
                code: this.code,
                name: this.name,
                names: names,
                country_code: this.country_code,
                phone_rule: this.phone_rule,
                status: this.status,
            }
            if(this.getCountry_View.name){ //編輯
                this.$store.dispatch('action_country_edit',data)
                this.$store.commit('clearCountry_View') //清除store.state 預防編輯過後，點擊新增會秀出上次編輯getCountry_View資訊
            }else { //新增
                this.$store.dispatch('action_country_add',data)
            }
        },
        // country凍結
        countryDel(country) {
            if(country.status == 1) { //狀態開啟時 走凍結路
                // console.log('凍結',country.id)
                this.$store.dispatch('action_country_delete', country.id)
            } else { //狀態凍結時 走開啟路
                // console.log('開啟',country.id)
                this.$store.dispatch('action_country_edit', {id:country.id,status:1})
            
            }
            
        },
    },
    created() {
        this.$store.dispatch('action_country')
        this.$store.dispatch('action_languages')
    }
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