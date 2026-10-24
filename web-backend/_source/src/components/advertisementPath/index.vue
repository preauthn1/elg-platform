<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('System_management')}} > {{$t('Advertising_path')}} > {{$t('management/search')}}</h4>
        </div>
        <a href="/advertisementPath/edit" class="btn btn-inverse btn-success">{{$t('Advertisement_path_Add')}}</a>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th>{{$t('path_name')}}</th>
                                        <th>{{$t('column')}}</th>
                                        <th>{{$t('image_width')}}</th>
                                        <th>{{$t('image_height')}}</th> 
                                        <th>{{$t('status')}}</th>                                                       
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(adPath,i) in getAdPath_list" :key="i">
                                        <td>
                                            <div class="mrg-top-15">
                                                <span>{{adPath.name}}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span>{{adPath.column}}</span>
                                            </div>
                                        </td>
                                        
                                        <td>
                                            <div class="relative mrg-top-15">
                                                <span class="pdd-left-20">{{adPath.width}}px</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="relative mrg-top-15">
                                                <span class="pdd-left-20">{{adPath.height}}px</span>
                                            </div>
                                        </td>        
                                        <td>
                                            <div class="mrg-top-15" :class="{'text-danger': adPath.status!== 1}">
                                                {{adPath.status == 1 ? '正常' : '停用'}}
                                            </div>
                                        </td>                                                
                                        <td>
                                            <a :href="'/advertisementPath/edit?id='+adPath.id" class="btn btn-default btn-sm">{{$t('Edit')}}</a>
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
</template>
<script>
import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getAdPath_list'])
    },
    created() {
        let data = {
            page: 1,
            per_page: 50
        }
        this.$store.dispatch('action_advertisementPath_list',data)
    },
}
</script>