<template>
<div>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('System_management')}} > {{$t('Country/city_management')}} > {{$t('Currency_management')}}</h4>
            </div>
            <a href="javascript:void(0)" @click="isShow=true" data-toggle="modal" data-target="#edit_currency" data-uid="0" class="btn btn-inverse btn-success edit">{{$t('currency_add')}}</a>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{$t('currency_name')}}</th>
                                            <th>{{$t('currency_shortName')}}</th>
                                            <th>{{$t('currency_rate')}}</th>
                                            <th>{{$t('status')}}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(currency,i) in getCurrency_List" :key="i">
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    
                                                    <div class="info">
                                                        <span class="title">{{currency.name}}</span>
                                                        <span class="sub-title">ID {{currency.id}}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">{{currency.code}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">{{currency.rate}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <b class="text-dark font-size-16" :class="currency.status !== 1 ? 'text-danger' : ''">
                                                        {{currency.status == 1 ? $t('enable') : $t('disable')}}</b>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button @click="toggleAction(currency.id)" class="btn btn-default btn-sm dropdown-toggle" type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu" :class="{show : editShow == currency.id}">
                                                            <li>
                                                                <a href="javascript:void(0)" @click="showEdit(currency)" data-toggle="modal" data-target="#edit_currency" class="edit">{{$t('Edit')}}</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="javascript:void(0)" @click="currencyDel(currency)" class="currency_toggle_status" :status="currency.status !== 1 ? 1 : 0">{{currency.status !== 1 ? $t('enable') : $t('disable')}}</a>
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
                                                <p class="mrg-btm-15 font-size-13">{{$t('currency_name')}}</p>
                                                <input v-model="name" type="text" class="form-control" placeholder="Taiwan" name="name">
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('currency_shortName')}}</p>
                                                <input v-model="code" type="text" class="form-control" placeholder="TW" name="code">
                                            </div>
                                            <div class="form-group">
                                                <p class="mrg-btm-15 font-size-13">{{$t('currency_rate')}}</p>
                                                <input v-model="rate" type="text" class="form-control" placeholder="1.0000" name="rate">
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
                                                <p class="mrg-btm-15 font-size-13">{{$t('currency_name')}}</p>
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
                        <button @click="closeEdit()" class="btn btn-default btn-sm" data-dismiss="modal">{{$t('cancel')}}</button>
                        <button @click="saveCurrency()" class="btn btn-primary btn-sm" data-dismiss="modal" id="save">{{$t('Save')}}</button>
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
        ...mapGetters(['getCurrency_List','getCurrency_View','getLanguages'])
    },
    watch: {
        getCurrency_View(currency) { //開啟編輯窗取到getCurrency_View 帶入編輯input
            this.id = currency.id
            this.name = currency.name
            this.code = currency.code
            this.rate = currency.rate
            this.status = currency.status
            if(currency.names){
                currency.names.forEach((e)=>{
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
            code: null,
            name: null,
            allLang: [],
            rate: null,
            status: false,
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
        showEdit(currency){ //開啟編輯彈窗，打'action_currency_view'取要編輯的貨幣資料
            this.isShow = true
            this.$store.dispatch('action_currency_view',currency.id)
        },
        closeEdit(){ //關閉新增編輯彈窗
            this.isShow = false
            this.langActive = 0
            // 多語系名稱清空，預防如下
            this.allLang = []
            //clearCurrency_View 清空store.state的view資料，預防先編輯貨幣之後再新增貨幣，編輯貨幣的資料會帶入新增貨幣的input內(因為新增編輯窗是共用)
            this.$store.commit('clearCurrency_View')
        },
        saveCurrency(){
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
                rate: this.rate,
                status: this.status,
            }
            // console.log('names',data)
            if(this.getCurrency_View.name){ //編輯
                this.$store.dispatch('action_currency_edit',data)
                this.$store.commit('clearCurrency_View') //清除store.state 預防編輯過後，點擊新增會秀出上次編輯getCurrency_View資訊
            }else { //新增
                this.$store.dispatch('action_currency_add',data)
            }
        },
        // country凍結
        currencyDel(currency) {
            if(currency.status == 1) { //狀態開啟時 走凍結路
                this.$store.dispatch('action_currency_delete', currency.id)
            } else { //狀態凍結時 走開啟路
                this.$store.dispatch('action_currency_edit', {id:currency.id,status:1})
            
            }
        },
    },
    created() {
        this.$store.dispatch('action_currency_list')
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