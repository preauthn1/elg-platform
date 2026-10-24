<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('System_management')}} >  {{$t('Advertising_path')}} > {{$route.query.id ? $t('Advertising_path_edit') : $t('Advertising_path_add')}}</h4>
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
                                                    <label><span v-if="!getAdPath_view.name" style="color:red">*</span>{{$t('path_name')}}</label>
                                                    <input v-model="name" type="text" placeholder="" class="form-control" name="name">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label><span v-if="!getAdPath_view.column" style="color:red">*</span>{{$t('column')}}</label>
                                                    <input v-model="column" type="text" placeholder="" class="form-control" name="column">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label><span v-if="!getAdPath_view.width" style="color:red">*</span>{{$t('image_width')}}</label>
                                                    <input v-model="width" type="text" placeholder="100px" class="form-control" name="width">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label><span v-if="!getAdPath_view.height" style="color:red">*</span>{{$t('image_height')}}</label>
                                                    <input v-model="height" type="text" placeholder="100px" class="form-control" name="height">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <div class="form-group">
                                                    <label>{{$t('status')}}</label>
                                                    <div class="col-md-10">
                                                        <div class="radio radio-inline">
                                                            <input v-model="status" type="radio" name="status" value="1" id="rad1" :checked="getAdPath_view.status == 1">
                                                            <label for="rad1">{{$t('enable')}}</label>
                                                        </div>
                                                        <div class="radio radio-inline">
                                                            <input v-model="status" type="radio" name="status" value="0" id="rad2" :checked="getAdPath_view.status !== 1">
                                                            <label for="rad2">{{$t('disable')}}</label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12 col-xs-6">
                                                <div class="text-right mrg-top-5">
                                                    <input type="button" class="btn btn-default" :value="$t('backPage')" onclick="history.back();" />
                                                    <button @click="saveAdPath()" class="btn btn-primary" id="save">{{$t('Save')}}</button>
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
        ...mapGetters(['getAdPath_view'])
    },
    watch: {
        getAdPath_view(e) { //監聽編輯api view資料回來，把資料帶進編輯input
            this.name = e.name
            this.column = e.column
            this.width = e.width
            this.height = e.height 
            this.status = e.status
        }
    },
    data() {
        return {
            name: '',
            column: '',
            width: '',
            height: '',
            status: 0
        }
    },
    methods: {
        saveAdPath() {
            let id = this.$route.query.id 
            if(id) { // 編輯
                let data = {
                    id: id, 
                    name: this.name,
                    column: this.column,
                    width: this.width,
                    height: this.height,
                    status: this.status
                }
                //路由有id代表編輯，所以打編輯'action_advertisementPath_edit'
                this.$store.dispatch('action_advertisementPath_edit',data)
            } else { // 新增
                let data = {
                    name: this.name,
                    column: this.column,
                    width: this.width,
                    height: this.height,
                    status: this.status
                }
                //路由沒有帶上id代表新增，所以打新增'action_advertisementPath_add'
                this.$store.dispatch('action_advertisementPath_add',data)
            }
        },
        View() { //route上有query id，代表為編輯，會去打view取資料
            let id = this.$route.query.id
            if(id){
                this.$store.dispatch('action_advertisementPath_view',id)
            }
        }
    },
    created() {
        this.View()
    },
}
</script>