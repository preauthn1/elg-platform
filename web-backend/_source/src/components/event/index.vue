<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('System_management')}} > {{$t('event_management')}}</h4>
        </div>
        <a href="/event/edit" class="btn btn-inverse btn-success">{{$t('event_add')}}</a>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="table-overflow">
                            <table id="dt-opt" class="table table-lg table-hover">
                                <thead>
                                    <tr>
                                        <th>{{$t('event_name')}}</th>
                                        <th>{{$t('event_conditions')}}</th>
                                        <th>{{$t('event_reward')}}</th>
                                        <th>{{$t('reward_quantity')}}</th>
                                        <th>{{$t('reward_use_expiry_date')}}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(event,i) in getEvent_list" :key="i">
                                        <td>
                                            <div class="mrg-top-15">
                                                <span>{{event.title}}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div v-for="(detail,i) in event.detail" :key="i" class="list-info mrg-top-10">
                                                <span class="pdd-left-20">{{getEventAction_Name(detail.action_id)}}{{' over '+detail.point+' betweent '+detail.start_time+' and '+detail.end_time}}</span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="relative mrg-top-15">
                                                <span class="text-info"><b>{{getPrizeType_Name(event.prize_type_id)}}</b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b>{{event.prize_number}}</b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="mrg-top-15">
                                                <span class="text-info"><b>{{event.expire_date.substr(0, 10)}}</b></span>
                                            </div>
                                        </td>
                                        <td>
                                            <a :href="'/event/edit?id='+ event.id" class="btn btn-default btn-sm">{{$t('Edit')}}</a>
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
        ...mapGetters(['getEvent_list','getPrizeType_list','getEventAction_list']),
        
    },
    watch: {
    },
    data() {
        return {
        }
    },
    methods: {
        // 獎勵種類 名稱，getEvent_list內只有id沒有name，所以去getPrizeType_list找name
        getPrizeType_Name(id) {
            if(this.getPrizeType_list.length>0) {
                let ary = this.getPrizeType_list.filter(e=>{
                    return e.id ==id
                })
                if(ary.length> 0){
                    return ary[0].name
                }
            }
        },
        // 取event action 名稱，getEvent_list內只有id沒有name，所以去getEventAction_list找name
        getEventAction_Name(id) {
            // console.log('run run run',this.getEventAction_list)
            if(this.getEventAction_list.length>0) {
                let ary = this.getEventAction_list.filter(e=>{
                    return e.value == id
                })
                if(ary.length > 0){
                    // console.log('name',ary[0].parameter)
                    return ary[0].parameter
                }
            }
        },
        List() {
            this.$store.dispatch('action_event_list',{page:1,per_page:100})
            this.$store.dispatch('action_prizeType_list','')
            this.$store.dispatch('action_param_list',['event_action'])
        },
    },
    created() {
        this.List()
    },
}
</script>