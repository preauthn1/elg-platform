<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('game_vendor_management')}} > {{$t('vendors_management')}} > {{$t('games_management')}} > {{this.$route.query.id ? $t('game_edit'): $t('game_add')}}</h4>
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
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('vendor_name')}}</label>
                                                    <select v-model="gameView.vendor_id" class="form-control" id="selectize-dropdown1" name="vendor_id">
                                                        <option v-for="(vendor,i) in getVendor_List" :key="i" :value="vendor.id">{{vendor.name}}</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('game_type')}}</label>
                                                    <select v-model="gameView.type_id" class="form-control" id="selectize-dropdown2" name="type_id">
                                                        <option v-for="(type,i) in getGameType_List" :key="i" :value="type.id">{{type.name}}</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="card">
                                            <div class="card-block">
                                                <div class="pill-info nav-vertical nav-stacked">
                                                    <ul class="nav nav-pills" role="tablist">
                                                        <li class="nav-item">
                                                            <a href="javascript:void(0)" @click="langActive = 0" :class="{active: langActive == 0}" class="nav-link" role="tab"
                                                                data-toggle="tab">{{$t('default')}}</a>
                                                        </li>
                                                        <li v-for="(lang,i) in getLanguages" :key="i" class="nav-item">
                                                            <a href="javascript:void(0)" @click="langActive=lang.id" class="nav-link" :class="{active: langActive == lang.id}" >{{lang.name}}</a>
                                                        </li>
                                                    </ul>
                                                    <div class="tab-content" style="margin-top: 5px">
                                                        <div role="tabpanel" class="tab-pane fade" :class="{in:langActive == 0,active: langActive == 0}" id="default">
                                                            <div class="form-group">
                                                                <p class="mrg-btm-15 font-size-13"><span style="color:red">*</span>{{$t('game_name')}}</p>
                                                                <input v-model="gameView.name" type="text" class="form-control" name="name">
                                                            </div>
                                                        </div>
                                                        <div v-for="(lang,i) in getLanguages" :key="i" role="tabpanel" class="tab-pane fade" :class="{in:langActive == lang.id,active: langActive == lang.id}">
                                                            <div class="form-group">
                                                                <p class="mrg-btm-15 font-size-13">{{$t('game_name')}}</p>
                                                                <input v-model="allLang[lang.id]" type="text" class="form-control">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('game_code')}}</label>
                                                    <input v-model="gameView.game_type" type="text" name="game_type" class="form-control">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>{{$t('show_sort')}}</label>
                                                    <input v-model="gameView.sort" type="text" name="sort" class="form-control" maxlength="8">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>{{$t('show_newGame_tag')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="gameView.is_new" type="radio" name="is_new" :value="1" id="n1">
                                                            <label for="n1">{{$t('show')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="gameView.is_new" type="radio" name="is_new" :value="0" id="n2">
                                                            <label for="n2">{{$t('noShow')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>{{$t('show_hotGame_tag')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="gameView.is_hot" type="radio" name="is_hot" :value="1" id="h1">
                                                            <label for="h1">{{$t('show')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="gameView.is_hot" type="radio" name="is_hot" :value="0" id="h2">
                                                            <label for="h2">{{$t('noShow')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>{{$t('status')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="gameView.status" type="radio" name="status" :value="1" id="rad1">
                                                            <label for="rad1">{{$t('enable')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="gameView.status" type="radio" name="status" :value="0" id="rad2">
                                                            <label for="rad2">{{$t('disable')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>{{$t('image')}}</label>
                                                    <div>
                                                        <label for="img-upload" class="pointer">
                                                            <img :src="gameView.image" id="show_image" style="max-height:300px;max-width:300px"/>
                                                            <input type="hidden" name="image">
                                                            <span class="btn btn-default display-block no-mrg-btm">Choose file</span>
                                                            <input @change="uploadImg" class="d-none" type="file" name="image_file" id="img-upload">
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 col-xs-6">
                                                <div class="text-right mrg-top-5">
                                                    <input type="button" class="btn btn-default" :value="$t('backPage')" onclick="history.back();"/>
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
        ...mapGetters(['getVendor_List','getGameType_List','getGame_View','getLanguages']),
    },
    watch: {
        getGame_View(game) {
            // 深度拷貝 預防vuex state變更抱錯
            this.gameView = JSON.parse(JSON.stringify(game))
            // 多語系名稱
            if(game.names){
                game.names.forEach((e)=>{
                    this.allLang[e.language_id] = e.name
                })
            }
        },
        getVendor_List(vendor){
            this.gameView.vendor_id = vendor[0].id
        },
        getGameType_List(type){
            this.gameView.type_id = type[0].id
        },
    },
    data() {
        return {
            langActive: 0,
            gameView: {
                id: null,
                name: null,
                vendor_id: null,
                type_id: null,
                game_type: null,
                is_new: 0,
                is_hot: 0,
                status: 0,
                image: null,
                sort: ''
            },

            allLang: []
        }
    },
    methods: {
        // 送出
        save() {
            // 判斷照片64base 未修改為 https
            let Img = this.gameView.image
            if(Img){
                if(Img.substr(0,4)=='http'){
                    Img = null
                }
            }
            // 多語名稱
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
                id: this.gameView.id,
                name: this.gameView.name,
                names: this.gameView.names, //多語名稱
                vendor_id: this.gameView.vendor_id,
                type_id: this.gameView.type_id,
                game_type: this.gameView.game_type,
                is_new: this.gameView.is_new,
                is_hot: this.gameView.is_hot,
                status: this.gameView.status,
                image: Img, //64base
            }
            if(this.$route.query.id){ //編輯
                this.$store.dispatch('action_game_edit',data)
            } else { //新增
                this.$store.dispatch('action_game_add',data)
            }
            
            
        },
        View(id) { //編輯路由會帶上id ，去撈view資料
            if(id){
                this.$store.dispatch('action_game_view',id)
            }
        },
        uploadImg(e) {
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
                    vm.gameView.image = base
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
        this.$store.dispatch('action_vendor_list')
        this.$store.dispatch('action_gameType_list',"")
        this.View(this.$route.query.id)
    },
}
</script>