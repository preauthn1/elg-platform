<template>
    <div class="main-content">
        <div class="container-fluid">
            <div class="page-title">
                <h4>{{$t('game_vendor_management')}} > {{$t('vendors_management')}}</h4>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-block">
                            <div class="table-overflow">
                                <table id="dt-opt" class="table table-lg table-hover">
                                    <thead>
                                        <tr>
                                            <th>{{$t('sort')}}</th>
                                            <th>{{$t('vendor_img')}}</th>
                                            <th>{{$t('vendor_name')}}</th>
                                            <th>{{$t('transfer_rate')}}</th>
                                            <th>{{$t('allot_rate')}}</th>
                                            <th>{{$t('status')}}</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(vendor,i) in getVendor_List" :key="i">
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">{{vendor.sort}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <img height="80px" :src="vendor.image">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="list-info mrg-top-10">
                                                    <div class="info">
                                                        <span class="title">{{vendor.name}}</span>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="relative mrg-top-15">
                                                    <span class="pdd-left-20">1:{{vendor.rate}}</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15">
                                                    <span class="text-info">{{vendor.percentage}}%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-15" :class="vendor.status !== 1 ? 'text-danger' : ''">
                                                    {{vendor.status == 1 ? $t('enable') : vendor.status == 2 ? $t('develop') : $t('disable')}}
                                                </div>
                                            </td>
                                            <td>
                                                <div class="mrg-top-10 text-center">
                                                    <div class="dropdown inline-block">
                                                        <button @click="toggleAction(vendor.id)" class="btn btn-default btn-sm dropdown-toggle"
                                                            type="button" data-toggle="dropdown">
                                                            <i class="ti-angle-down font-size-9"></i>
                                                        </button>
                                                        <ul class="dropdown-menu" :class="{show: toggle == vendor.id}">
                                                            <li>
                                                                <a :href="'/vendor/edit?id='+ vendor.id">{{$t('Edit')}}</a>
                                                            </li>
                                                            <li>
                                                                <a :href="'/game/index?vid='+vendor.id">{{$t('games_management')}}</a>
                                                            </li>
                                                            <li class="divider"></li>
                                                            <li>
                                                                <a href="javascript:void(0)" @click="toggleStatus(vendor)" class="vendor_toggle_status" :status="vendor.status !== 1 ? 1 : 0 ">
                                                                    {{vendor.status !== 1 ? $t('enable') : $t('disable') }}</a>
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
</template>
<script>
import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getVendor_List']),
    },
    data() {
        return {
            toggle: null,
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
        toggleStatus(vendor) {
            if(vendor.status == 1) { //狀態開啟時 走凍結路
                // console.log('凍結',vendor.id)
                this.$store.dispatch('action_vendor_edit', {id:vendor.id,status:0})
            } else { //狀態凍結時 走開啟路
                // console.log('開啟',vendor.id)
                this.$store.dispatch('action_vendor_edit', {id:vendor.id,status:1})
            }
        }
    },
    created() {
        this.$store.dispatch('action_vendor_list')
    },
}
</script>