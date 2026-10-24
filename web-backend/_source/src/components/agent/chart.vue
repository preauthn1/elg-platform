<template>
<div class="card">
    <div class="card-heading border bottom">
        <h4 class="card-title">{{$t('lower_level_agents/members')}}</h4>
    </div>
    <div class="card-block">
        <!-- <P>{{$t('agent')}}: {{getNumber_of_People.agent}} {{$t('person')}}</P>
        <P>{{$t('member')}}: {{getNumber_of_People.member}} {{$t('person')}}</P> -->
        <!-- <div id="pie-chart">
            <svg height="450"></svg>
        </div> -->
        <ve-pie :data="chartData" :settings="chartSettings"></ve-pie>
    </div>
</div>
</template>
<script>
import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getNumber_of_People']),
    },
    watch:{
        getNumber_of_People(e) {
            this.chartData.rows[0].people = e.agent
            this.chartData.rows[1].people = e.member
        }
    },
    data() {
        this.chartSettings = {
            dataType: 'normal',
            selectedMode: 'single'
        }
        return {
            chartData: {
                columns: ['id', 'people'],
                rows: [
                    { 'id': this.$t('agent'), 'people': null },
                    { 'id': this.$t('member'), 'people': null },
                ]
            }
        }
    },
    methods:{
        
    },
    created() {
        this.$store.dispatch('action_numberOfPeople',this.$route.query.id)
    }
}
</script>