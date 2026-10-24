<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('game_vendor_management')}} > {{$t('vendors_management')}} > {{$t('Edit')}}</h4>
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
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>{{$t('vendor_name')}}</label>
                                                    <input v-model="name" type="text" name="name" class="form-control">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>{{$t('transfer_rate')}}</label>
                                                    <input v-model="rate" type="number" placeholder="1:xx" name="rate" class="form-control">
                                                </div>
                                                <div class="form-group">
                                                    <label>{{$t('allot_rate')}}</label>
                                                    <input v-model="percentage" type="number" placeholder="ex: 20%" name="percentage" class="form-control">
                                                </div>
                                                <div class="form-group">
                                                    <label>{{$t('sort')}}</label>
                                                    <input v-model="sort" type="text" placeholder="ex: 1" name="sort" class="form-control">
                                                </div>
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
                                                        <div class="radio radio-inline">
                                                            <input v-model="status" type="radio" name="status" value="2" id="rad3">
                                                            <label for="rad3">{{$t('develop')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <p class="mrg-btm-15 font-size-13">{{$t('vendor_img')}}</p>
                                                    <div>
                                                        <label class="pointer">
                                                            <img :src="vendorImg" id="show_image"
                                                                style="max-height:200px;max-width:200px" />
                                                            <input type="hidden" name="image">
                                                            <span
                                                                class="btn btn-default display-block no-mrg-btm">Choose
                                                                file</span>
                                                            <input @change="uploadImg" class="d-none upload_image" type="file"
                                                                data-location='image'>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <p class="mrg-btm-15 font-size-13">{{$t('vendor_app_icon')}}</p>
                                                    <div>
                                                        <label class="pointer">
                                                            <img :src="vendorIcon" id="show_app_icon" style="max-height:200px;max-width:200px" />
                                                            <input type="hidden" name="app_icon">
                                                            <span
                                                                class="btn btn-default display-block no-mrg-btm">Choose
                                                                file</span>
                                                            <input @change="uploadIcon" class="d-none upload_image" type="file"
                                                                data-location='app_icon'>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <label>{{$t('agent_show_this_vendor')}}</label>
                                                <div v-for="(list,i) in vendorTmpData" :key="i" class="row">
                                                    <div class="col-md-2" style="line-height: 40px;">
                                                        {{list.username}}
                                                    </div>
                                                    <div class="col-md-2">
                                                        <div class="radio radio-inline">
                                                            <input v-model="list.vendor_status" :value="1" type="radio" :id="list.username+'1'">
                                                            <label :for="list.username+'1'">{{$t('show')}}</label>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <div class="radio radio-inline">
                                                            <input v-model="list.vendor_status" :value="0" type="radio" :id="list.username+'0'">
                                                            <label :for="list.username+'0'">{{$t('noShow')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 col-xs-6">
                                                <div class="text-right mrg-top-5">
                                                    <input type="button" class="btn btn-default" :value="$t('backPage')" onclick="history.back();" />
                                                    <button @click="save()" type="submit" class="btn btn-primary" id="save">{{$t('Save')}}</button>
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
        ...mapGetters(['getVendor_View','getAgent_Vendor_List'])
    },
    watch: {
        getVendor_View(e) {
            this.id =e.id
            this.name=e.name
            this.rate=e.rate
            this.sort=e.sort
            this.percentage=e.percentage
            this.status=e.status
            this.vendorImg=e.image
            this.vendorIcon=e.app_icon
        },
        getAgent_Vendor_List(e) {
            this.vendorTmpData = JSON.parse(JSON.stringify(e))
        }
    },
    data() {
        return {
            id: null,
            name: null,
            rate: null,
            percentage: null,
            sort: null,
            status: false,
            vendorImg: null,
            vendorIcon: null,
            // vendor_status: [],
            vendorTmpData: []
        }
    },
    methods: {
        uploadImg(e) { // 上傳圖片
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
                    vm.vendorImg = base
                })
            }
        },
        uploadIcon(e) { //上傳ICON
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
                    vm.vendorIcon = base
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
        // 送出
        save() {
            let vendorImg = this.vendorImg
            let vendorIcon = this.vendorIcon
            if(this.vendorImg){
                if(this.vendorImg.substr(0,4)=='http'){
                    vendorImg = null
                }
            }
            if(this.vendorIcon){
                if(this.vendorIcon.substr(0,4)=='http'){
                    vendorIcon = null
                }
            }
            let data = {
                id: this.id,
                name: this.name,
                rate: this.rate,
                percentage: this.percentage,
                sort: this.sort,
                status: this.status,
                vendorImg: vendorImg,
                vendorIcon: vendorIcon,
            }
            this.$store.dispatch('action_vendor_edit',data)
            
            this.vendorTmpData.forEach(i => { //廠商管理>設定營運商是否顯示此遊戲商，需一支一支設定是否顯示遊戲商
                i.vendor_id = this.id
            })
            this.$store.dispatch('action_agentVendor_setting',this.vendorTmpData)
        },
        // 編輯View
        View() {
            let id = this.$route.query.id
            if(id){
                this.$store.dispatch('action_vendor_view',id)
                this.$store.dispatch('action_agentVendor_list',id)
                this.id = id
            }
        }
    },
    created() {
        this.View()
    },
}
</script>