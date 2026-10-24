<template>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('System_management')}} > {{$t('template_management')}}</h4>
            </div>
            
            <div class="row">
             
                <div v-for="(template,i) in getTemplate_List" :key="i" class="col-md-4">
                    <div class="card">
                        <div class="card-block">
                            <h5 class="mrg-btm-10">{{template.name}}</h5>
                            <img class="img-fluid mrg-horizon-auto mrg-btm-15" :src="template.thumbnail">
                            <div class="row">
                                <div class="col-lg-8 col-md-12">
                                </div>
                                <div class="col-lg-4 col-md-12 text-right pdd-top-5">
                                    <button @click="showEdit(template)" data-toggle="modal" data-target="#template_modal" class="btn btn-sm btn-primary edit">{{$t('Edit')}}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 網域背景遮罩 -->
        <div class="modal-backdrop fade maskNone" :class="{show: isShow,showBlock: isShow}"></div>
        <!-- Modal START-->
        <div class="modal slide-in-right modal-right fade" :class="{show: isShow,showBlock: isShow}" id="domain_add">
            <div class="modal-dialog" role="document">
                <div class="modal-content" id="domain_add_content">
                    <div class="side-modal-wrapper">
                        <div class="vertical-align">
                            <div class="table-cell">
                                <div class="pdd-horizon-15">
                                    <h4>{{$t('template_edit')}}</h4>
                                    <p class="mrg-btm-15 font-size-13">{{$t('can_change_template_information')}}</p>
                                    <div id="data-form">
                                        <div class="form-group">
                                            <p class="mrg-btm-15 font-size-13">{{$t('template_name')}}</p>
                                            <input v-model="name" type="text" class="form-control" placeholder="example.invalid" name="domain">
                                        </div>
                                        <div class="form-group">
                                            <p class="mrg-btm-15 font-size-13">{{$t('template_img')}}</p>
                                            <div>
                                                <label for="img-upload" class="pointer">
                                                    <img :src="thumbnail" id="show_image" style="max-height:300px;max-width:300px"/>
                                                    <input type="hidden" name="image" >
                                                    <span class="btn btn-default display-block no-mrg-btm">Choose file</span>
                                                    <input @change="upload" class="d-none" type="file" name="image_file" id="img-upload">
                                                </label>
                                            </div>
                                        </div>
                                        <div class="form-group">
                                            <p class="mrg-btm-15 font-size-13">{{$t('status')}}</p>
                                            <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                                                <input type="checkbox" v-model="status" name="status" id="toggle1">
                                                <label for="toggle1"></label>
                                            </div>
                                        </div>
                                        <button class="btn btn-info btn-sm" @click="saveTemplate()">{{$t('Confirm')}}</button>
                                        <button class="btn btn-secondary btn-sm" @click="isShow = false">{{$t('cancel')}}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Modal END-->
    </div>
</template>
<script>
import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getTemplate_List','getTemplate_View']),
    },
    watch: {
        getTemplate_View(t){ //編輯模板取到getTemplate_View 帶入編輯input
            this.id = t.id
            this.name = t.name
            this.thumbnail = t.thumbnail
            this.status = t.status
        }
    },
    data() {
        return {
            isShow: false,
            // 資料
            id: null,
            name: null,
            thumbnail: null,
            status: null,
        }
    },
    methods:{
        showEdit(template){ 
            this.isShow = true
            this.$store.dispatch('action_template_view',template.id)
        },
        saveTemplate(){
            let Img = this.thumbnail
            if(this.thumbnail){
                if(this.thumbnail.substr(0,4)=='http'){
                    Img = null
                }
            }
            let data = {
                id: this.id,
                name: this.name,
                thumbnail: Img,
                status: this.status,
            }
            this.$store.dispatch('action_template_edit',data)
        },
        upload(e) {
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
                    vm.thumbnail = base
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
    },
    created() {
        this.$store.dispatch('action_template_list',{page:1,per_page:1000})
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