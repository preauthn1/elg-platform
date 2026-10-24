<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('System_management')}} > {{$t('Advertising_management')}}</h4>
        </div>
        <a href="/advertisement/edit" class="btn btn-inverse btn-success">{{$t('Advertisement_Add')}}</a>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="row">
                            <div class="col-md-12">
                                <h4 class="card-title">{{$t('search')}}</h4>
                                <div class="form-horizontal mrg-top-40 pdd-right-30">
                                    <div class="form-group row">
                                        <label for="form-1-1" class="col-md-2 control-label">{{$t('types')}}</label>
                                        <div class="col-md-6" style="position: relative;">
                                            <div class="selects" @click="selectShow = !selectShow">
                                                <span v-for="(name,i) in adPath_id" :key="i">{{select_name(name)}}</span>
                                            </div>
                                            <i class="ti-angle-down font-size-10 select_arrow" :class="{show:selectShow}" @click="selectShow = !selectShow"></i>
                                            <select v-model="adPath_id" class="item-info form-control select_item" :class="{show:selectShow}" multiple>
                                                <option v-for="(adPath,i) in getAdPath_list" :key="i+adPath.name" :value="adPath.id">
                                                    {{adPath.name}}</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-2 control-label">{{$t('start/end_date')}}</label>
                                        <div class="col-md-3">
                                            
                                             <vue-datepicker-local class="form-control" v-model="range"/>
                                        </div>
                                    </div>
                                    <div class="form-group row">
                                        <label for="form-1-2" class="col-md-2 control-label">&nbsp;</label>
                                        <div class="col-md-3">
                                            <button @click="searchAD()" class="btn btn-primary" id="search">{{$t('start_search')}}</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th>{{$t('types_name')}}</th>
                                        <th>{{$t('images')}}</th>
                                        <!-- <th>超連結</th> -->
                                        <th>{{$t('start_date')}}</th>
                                        <th>{{$t('end_date')}}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody v-for="(ad,i) in getAd_list" :key="i" v-if="ad.images.length > 0">
                                    <tr v-for="(image,i) in ad.images" :key="i + image.id">
                                        <td>
                                            <div class="mrg-top-15">
                                                <span>{{ad.name}}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="list-info mrg-top-10">
                                                <img class="thumb-img" :src="image.image">
                                            </div>
                                        </td>
                                        <!-- <td>
                                            <div class="relative mrg-top-15">
                                                <span class="pdd-left-20"><?= $image['url'] ?></span>
                                            </div>
                                        </td> -->
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b>{{image.start_time}}</b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b>{{image.end_time}}</b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <a :href="'/advertisement/edit?id='+image.id"
                                                class="btn btn-default btn-sm">{{$t('Edit')}}</a>
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
// import DateRangePicker from 'vue2-daterange-picker'
// import 'vue2-daterange-picker/dist/vue2-daterange-picker.css'
import VueDatepickerLocal from 'vue-datepicker-local'
export default {
    components: { VueDatepickerLocal },
    computed: {
        ...mapGetters(['getAdPath_list','getAd_list'])
    },
    watch: {

    },
    data() {
        return {
            selectShow: false,
            range: [new Date(),new Date()],
            adPath_id: [],
            adAry: []
        }
    },
    methods: {
        searchAD() { //搜尋廣告
            this.$store.commit('clear_ad_list')
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
            let data = {
                start_time: startDate,
                end_time: endDate,
                path_id: this.adPath_id,
                path_name: this.adAry
            }
            console.log('searchA',startDate ,'+',endDate)
            this.$store.dispatch('action_advertisement_list',data)
        },
        select_name(id){ //類型名稱需去getAdPath_list取name
            let nameAry = this.getAdPath_list.filter(e => {
                return id == e.id
            })
            let name = nameAry[0].name
            return name
        }
    },
    created() {
        this.$store.dispatch('action_advertisementPath_list',{page: 1,per_page: 50})
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
.selects {
    border: 1px solid rgba(50,50,50,.1);
    min-height: 40px;
    padding-right: 40px
}
.selects span {
    display: inline-block;
    padding: 4px 10px;
    margin: 3px;
    border: 1px solid rgba(50,50,50,.8);
    border-radius: 15px;
    color: rgba(50,50,50,.8)
}
.select_item {
    width: calc(100% - 30px);
    top: 40px;
    left: 15px;
    height: auto;
    position: absolute;
    z-index: 9;
    display: none;
}
.select_item.show {
    display: block;
}
.select_arrow {
    position: absolute;
    top: 15px;
    right: 30px;
    transition: 0.2s;
}
.select_arrow.show {
    transform: rotateZ(180deg);
    transition: 0.2s;
}
</style>