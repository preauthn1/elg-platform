<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('System_management')}} > Banner {{$t('Management')}} > {{$t('Edit')}} Banner</h4>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="row relative">
                            <div id="shipping-form" class="width-100">
                                <div id="rootwizard" class="form-wizard col-md-10 ml-auto mr-auto">
                                    <ul class="nav nav-pills nav-fill">
                                        <li class="nav-item">
                                            <a @click="Step(1)" href="javascript:void(0)" data-toggle="tab" :class="{active:Active==1}">
                                                <span class="step">1</span>
                                                <span class="title">{{$t('lang/path/date')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(2)" href="javascript:void(0)" data-toggle="tab" :class="{active:Active==2}">
                                                <span class="step">2</span>
                                                <span class="title">{{$t('setting_country')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(3)" href="javascript:void(0)" data-toggle="tab" :class="{active:Active==3}">
                                                <span class="step">3</span>
                                                <span class="title">{{$t('setting_Agent')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(4)" href="javascript:void(0)" data-toggle="tab" id="tab4" :class="{active:Active==4}">
                                                <span class="step">4</span>
                                                <span class="title">{{$t('hyperlink/upload_image')}}</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div id="bar" class="progress progress-info" style="width: 75%">
                                        <div class="progress-bar" role="progressbar" aria-valuenow="0"
                                            aria-valuemin="0" aria-valuemax="100" style="width: 0%;"></div>
                                    </div>
                                    <div class="tab-content">
                                        <!-- step1 -->
                                        <div id="step1" class="tab-pane fade" :class="{in: In == 1,active: Active == 1}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label>{{$t('select_adContent_lang')}}</label>
                                                                <select v-model="language" id="banner_language" class="form-control"
                                                                    data-live-search="true" name="language">
                                                                    <option v-for="(lang,i) in getLanguages" :key="i" :value="lang.id">
                                                                        {{lang.name}}</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label>{{$t('select_adPath')}}</label>
                                                                <select v-model="adPath" id="banner_language" class="form-control"
                                                                    data-live-search="true" name="path_id">
                                                                    
                                                                    <option v-for="(path,i) in getAdPath_list" :key="i" :value="path.id">
                                                                        {{path.name}}</option>
                                                                    
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label>{{$t('select_date')}}</label>
                                                                <!-- <input type="text" id="date-range-picker"
                                                                    class="form-control"
                                                                    placeholder="Date range picker" name="range" /> -->
                                                                <vue-datepicker-local class="form-control" v-model="range"/>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- step2 -->
                                        <div id="step2" class="tab-pane fade" :class="{in: In == 2,active: Active == 2}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="card">
                                                        <div class="card-block">
                                                            <div class="table-overflow">
                                                                <table id="dt-opt" class="table table-lg table-hover">
                                                                    <thead>
                                                                        <tr>
                                                                            <th></th>
                                                                            <th>{{$t('country_name')}}</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr v-for="(country,i) in countryAry" :key="i">
                                                                            <td>
                                                                                <div class="checkbox mrg-left-20">
                                                                                    <input v-model="countryGroup" :value="country.id" :id="country.name" :name="country.name" type="checkbox">
                                                                                    <label :for="country.name"></label>
                                                                                </div>
                                                                            </td>
                                                                            
                                                                            <td>
                                                                                <div class="list-info mrg-top-10">
                                                                                    <img class="thumb-img"
                                                                                        src="assets/images/avatars/thumb-1.jpg"
                                                                                        alt="">
                                                                                    <div class="info">
                                                                                        <span
                                                                                            class="title">{{country.name}}</span>
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
                                        <!-- step3 -->
                                        <div id="step3" class="tab-pane fade" :class="{in: In == 3,active: Active == 3}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="card">
                                                        <div class="card-block">
                                                            <div class="table-overflow">
                                                                <table id="dt-opt1"
                                                                    class="table table-lg table-hover">
                                                                    <thead>
                                                                        <tr>
                                                                            <th></th>
                                                                            <th>{{$t('agent_name')}}</th>
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        <tr v-for="(agent,i) in getAgent_List" :key="i">
                                                                            <td>
                                                                                <div class="checkbox mrg-left-20">
                                                                                    <input v-model="agentGroup" :value="agent.id" :id="agent.username" :name="agent.username" type="checkbox">
                                                                                    <label :for="agent.username"></label>
                                                                                </div>
                                                                            </td>
                                                                            <td>
                                                                                <div class="list-info mrg-top-10">
                                                                                    <img class="thumb-img"
                                                                                        src="assets/images/avatars/thumb-1.jpg"
                                                                                        alt="">
                                                                                    <div class="info">
                                                                                        <span
                                                                                            class="title">{{agent.username}}</span>
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
                                        <!-- step4 -->
                                        <div id="step4" class="tab-pane fade" :class="{in: In == 4,active: Active == 4}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label>{{$t('hyperlink')}}</label>
                                                                <select v-model="linkType" id="type" class="form-control"
                                                                    data-live-search="true" name="type">
                                                                    <option value="1">
                                                                        {{$t('advertisement_type_game_type')}}
                                                                    </option>
                                                                    <option value="2">
                                                                        {{$t('advertisement_type_game')}}
                                                                    </option>
                                                                    <option value="3">
                                                                        {{$t('advertisement_type_vendor')}}
                                                                    </option>
                                                                </select>
                                                                <br>
                                                                <select v-model="gameType_id" v-if="linkType == 1" id="game_type_id" class="form-control" data-live-search="true" name="game_type_id">
                                                                    <option v-for="(gameType,i) in getGameType_List" :key="i" :value="gameType.id">
                                                                        {{gameType.name}}</option>
                                                                </select>
                                                                <select v-model="game_id" v-else-if="linkType == 2" id="game_id" class="form-control" data-live-search="true" name="game_id">
                                                                    <option v-for="(game,i) in getGame_List" :key="i" :value="game.id">
                                                                        {{game.name}}</option>
                                                                </select>
                                                                <select v-model="vendor_id" v-else-if="linkType == 3" id="vendor_id" class="form-control" data-live-search="true" name="vendor_id">
                                                                    <option v-for="(vendor,i) in getVendor_List" :key="i" :value="vendor.id">
                                                                        {{vendor.name}}</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6" style="text-align:center">
                                                            <p class="mrg-top-10 text-dark"> <b>{{$t('upload_Mobile_img')}}</b></p>
                                                            <div>
                                                                <label for="img-upload" class="pointer">
                                                                    <!-- <img src="<?= $advertisement['image'] ?>"
                                                                        id="show_image"
                                                                        style="max-height:300px;max-width:300px" /> -->
                                                                    <img :src="banner_phone" style="max-height:300px;max-width:300px" />
                                                                    <input type="hidden" name="img_image">
                                                                    <span
                                                                        class="btn btn-default display-block no-mrg-btm">Choose
                                                                        file</span>
                                                                    <input @change="uploadPhone" class="d-none" type="file" name="image" id="img-upload">
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6" style="text-align:center">
                                                            <p class="mrg-top-10 text-dark"> <b>{{$t('upload_PC_img')}}</b></p>
                                                            <div>
                                                                <label for="img-upload_pc" class="pointer">
                                                                    <!-- <img src="<?= $advertisement['image_pc'] ?>"
                                                                        id="show_image_pc"
                                                                        style="max-height:300px;max-width:300px" /> -->
                                                                    <img :src="banner_pc" style="max-height:300px;max-width:300px" />
                                                                    <input type="hidden" name="img_image_pc">
                                                                    <span
                                                                        class="btn btn-default display-block no-mrg-btm">Choose
                                                                        file</span>
                                                                    <input @change="uploadPC" class="d-none" type="file" name="image_pc" id="img-upload_pc">
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div>
                                        <div class="wizard-pager">
                                            <div class="">
                                                <button :disabled="Active==1" @click="Previous_Step()" type="button" class="btn btn-default button-previous btn-rounded">{{$t('previousPage')}}</button>
                                                <button :disabled="Active==4" @click="Next_Step()" type="button" class="btn btn-primary button-next pull-right btn-rounded">{{$t('nextPage')}}</button>
                                                
                                                <button @click="saveBanner()" type="button" class="btn btn-success pull-right btn-rounded"
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
</template>
<script>
import { mapGetters } from 'vuex'
import VueDatepickerLocal from 'vue-datepicker-local'
export default {
    components: { VueDatepickerLocal },
    computed: {
        ...mapGetters(['getLanguages','getAdPath_list','getAgent_List','getGame_List','getGameType_List','getVendor_List','getAd_view'])
    },
    watch: {
        getGame_List(e){ //預設
            this.game_id = e[0].id
        },
        getAd_view(e) {
            if(e.game_type_id){
                this.linkType = 1
                this.gameType_id = e.game_type_id
            }else if(e.game_id) {
                this.linkType = 2
                this.game_id = e.game_id
            }else if(e.vendor_id) {
                this.linkType = 3
                this.vendor_id = e.vendor_id
            }
            this.language = e.language_id
            this.adPath = e.path_id
            this.countryGroup = JSON.parse(e.country_id) 
            this.agentGroup = JSON.parse(e.agent_id)
            this.banner_phone = e.image
            this.banner_pc = e.image_pc

            // 時間
            let S = e.start_time.split(' ')[0].split('-')
            let ST = e.start_time.split(' ')[1].split(':')
            let start = new Date(S[0],S[1] - 1,S[2], ST[0] ,ST[1], ST[2])
            let E = e.end_time.split(' ')[0].split('-')
            let ET = e.end_time.split(' ')[1].split(':')
            let end = new Date(E[0],E[1] - 1,E[2], ET[0], ET[1], ET[2])
            this.range= [start,end]
        },
    },
    data(){
        return {
            In:1,
            Active:1,
            // stpe1
            language: 1,
            adPath: 1,
            range:[new Date(),new Date()],
            // stap2
            countryAry: [],
            countryGroup: [],
            // step3
            agentGroup: [],
            // step4
            linkType: 1, //超連結類型
            gameType_id: 1,
            game_id: 1,
            vendor_id: 1,
            banner_phone: null,
            banner_pc: null
        }
    },
    
    methods: {
        Step(c){ //選取步驟
            this.In = c
            this.Active = c
        },
        Previous_Step() { //上一步驟
            let In = this.In
            let Active = this.Active
            if(In >= 2 && Active >= 2){
                In--
                Active--
                this.In = In
                this.Active = Active
            }
        },
        Next_Step() { //下一步驟
            let In = this.In
            let Active = this.Active
            if(In <= 3 && Active <= 3){
                In++
                Active++
                this.In = In
                this.Active = Active
            }
        },
        saveBanner(){ 
            // 時間
            let start = this.range[0]
            let end = this.range[1]
            let startY = start.getFullYear()
            let startM = start.getMonth() + 1
            let startD = start.getDate()
            let endY = end.getFullYear()
            let endM = end.getMonth() + 1
            let endD = end.getDate()
            if(startM < 10){
                startM = '0'+startM
            }
            if(startD < 10) {
                startD = '0'+startD
            }
            if(endM < 10){
                endM = '0'+endM
            }
            if(endD < 10) {
                endD = '0'+endD
            }
            let startDate = startY+'-'+startM+'-'+startD+' 00:00:00'
            let endDate = endY+'-'+endM+'-'+endD+' 23:59:59'

            if(this.linkType == 1){
                this.game_id = null
                this.vendor_id = null
            }else if (this.linkType == 2){
                this.gameType_id = null
                this.vendor_id = null
            }else if (this.linkType == 3){
                this.gameType_id = null
                this.game_id = null
            }
            //資料
            if(this.$route.query.id) { // 編輯
            // console.log('http',this.banner_phone.substr(0,4))
                let phoneImg = this.banner_phone
                let pcImg = this.banner_pc
                if(this.banner_phone){
                    if(this.banner_phone.substr(0,4)=='http'){
                        phoneImg = null
                    }
                }
                if(this.banner_pc){
                    if(this.banner_pc.substr(0,4)=='http'){
                        pcImg = null
                    }
                }
                let data = {
                    // 必填
                    id: this.$route.query.id,
                    // 選
                    path_id: this.adPath,
                    language_id: this.language,
                    start_time: startDate,
                    end_time: endDate, 
                    country_id: JSON.stringify(this.countryGroup),
                    agent_id:  JSON.stringify(this.agentGroup),
                    image: phoneImg,
                    image_pc: pcImg,
                    game_id: this.game_id,
                    game_type_id: this.gameType_id,
                    vendor_id: this.vendor_id
                }
                this.$store.dispatch('action_advertisement_edit',data)
            } else { //新增
                let data = {
                    // 必田
                    path_id: this.adPath,
                    language_id: this.language,
                    start_time: startDate,
                    end_time: endDate, 
                    // 選
                    country_id: JSON.stringify(this.countryGroup),
                    agent_id:  JSON.stringify(this.agentGroup),
                    image: this.banner_phone,
                    image_pc: this.banner_pc,
                    game_id: this.game_id,
                    game_type_id: this.gameType_id,
                    vendor_id: this.vendor_id
                }
                this.$store.dispatch('action_advertisement_add',data)
            }
        },
        uploadPhone(e) {
            let file = e.target.files[0]
            if (!/image\/\w/.test(file.type)) {
                alert('請確保檔案為影象型別')
                return false
            }
            let reader = new FileReader()
            let vm = this
            reader.readAsDataURL(file)
            reader.onload = function(e) {
                vm.dealImage(this.result, { width: 1920 }, function(base) {
                    vm.banner_phone = base
                })
            }
        },
        uploadPC(e) {
            let file = e.target.files[0]
            if (!/image\/\w/.test(file.type)) {
                alert('請確保檔案為影象型別')
                return false
            }
            let reader = new FileReader()
            let vm = this
            reader.readAsDataURL(file)
            reader.onload = function(e) {
                vm.dealImage(this.result, { width: 1920 }, function(base) {
                    vm.banner_pc = base
                })
            }
        },
        dealImage(path, obj, callback) {
            var img = new Image()
            img.src = path
            img.onload = function() {
                var that = this
                // 預設按比例壓縮
                var w = that.width,
                h = that.height,
                scale = w / h
                w = obj.width || w
                h = obj.height || w / scale
                var quality = 0.7 // 預設圖片質量為0.7
                //生成canvas
                var canvas = document.createElement('canvas')
                var ctx = canvas.getContext('2d')
                // 建立屬性節點
                var anw = document.createAttribute('width')
                anw.nodeValue = w
                var anh = document.createAttribute('height')
                anh.nodeValue = h
                canvas.setAttributeNode(anw)
                canvas.setAttributeNode(anh)
                ctx.drawImage(that, 0, 0, w, h)
                // 影象質量
                if (obj.quality && obj.quality <= 1 && obj.quality > 0) {
                quality = obj.quality
                }
                // quality值越小，所繪製出的影象越模糊
                var base64 = canvas.toDataURL('image/jpeg', quality)
                // 回撥函式返回base64的值
                callback(base64)
            }
        },
        View() {
            let id = this.$route.query.id
            if(id){
                this.$store.dispatch('action_advertisement_view',id)
            }
        }
    },
    created() {
        // step1
        this.$store.dispatch('action_advertisementPath_list',{page:1,per_page:1000})
        this.$store.dispatch('action_languages')
        // step2
        let country = JSON.parse(sessionStorage.getItem('country_list'))
        this.countryAry = country
        // step3
        this.$store.dispatch('action_agent_list',{page:1,per_page:1000})
        // step4
        this.$store.dispatch('action_vendor_list',1)
        this.$store.dispatch('action_gameType_list',1)
        this.$store.dispatch('action_game_list',{page:1,per_page:1000})

        this.View()
    },
}
</script>
<style lang="css" scoped>
.datepicker {
    padding: 0 !important
}
.datepicker-range {
    min-width: auto;
}
.item-info {
    height: 100%;
}
</style>