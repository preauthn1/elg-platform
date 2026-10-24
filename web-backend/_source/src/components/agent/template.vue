<template>
<div class="card">
    <div class="card-heading border bottom">
        <h4 class="card-title">{{$t('template_setting')}}</h4>
    </div>
    <div class="card-block">
        <div class="row">
            <div v-for="(template,i) in getAgent_Template_List" :key="i" class="col-md-6">
                <div class="card">
                    <div class="card-block">
                        <h4 class="card-title">{{template.template_name}}</h4>
                        <div class="card-media">
                            <img class="img-responsive" :src="template.template_thumbnail">
                        </div>
                        <div class="mrg-top-25" style="text-align:center">
                            <a href="javascript:void(0)" @click="templateSet(template)" class="btn no-mrg-btm status_btn" :class="{'btn-primary':template.status == 1,'btn-default':template.status !== 1}">
                                <span class="ei" :class="{'ei-checked': template.status == 1,'ei-close': template.status !== 1}"></span>{{template.status == 1? $t('enable') : $t('disable')}}
                            </a>
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
        ...mapGetters(['getAgent_Template_List']),
    },
    watch: {
        
    },
    data() {
        return {
            
        }
    },
    methods:{
        templateSet(template) {
            let Status
            if(template.status == 1) {
                Status = 0
            } else {
                Status = 1
            }
            let data = {
                agent_id: this.$route.query.id,
                template_id: template.id,
                status: Status,
            }
            console.log('set',data)
            this.$store.dispatch('action_agentTemplate_setting',data)
        }
    },
    created() {
        this.$store.dispatch('action_agentTemplate_list', {agent_id:this.$route.query.id, page:1, per_page:100})
    }
}
</script>