<template>
<div class="main-content">
    <div class="container-fluid">
        <div class="page-title">
            <h4>{{$t('System_management')}} > {{$t('event_management')}} > {{this.$route.query.id ? $t('Edit') : $t('Add')}} > {{$t('event')}}</h4>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-block">
                        <div class="row relative">
                            <form id="shipping-form" class="width-100" method="get">
                                <div id="rootwizard" class="form-wizard col-md-12 ml-auto mr-auto">
                                    <ul class="nav nav-pills nav-fill">
                                        <li class="nav-item">
                                            <a @click="Step(1)" href="javascript:void(0)" :class="{active:Active==1}" data-toggle="tab">
                                                <span class="step">1</span>
                                                <span class="title">{{$t('title/content')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(2)" href="javascript:void(0)" :class="{active:Active==2}" data-toggle="tab">
                                                <span class="step">2</span>
                                                <span class="title">{{$t('setting_conditions')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(3)" href="javascript:void(0)" :class="{active:Active==3}" data-toggle="tab">
                                                <span class="step">3</span>
                                                <span class="title">{{$t('setting_reward')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(4)" href="javascript:void(0)" :class="{active:Active==4}" data-toggle="tab">
                                                <span class="step">4</span>
                                                <span class="title">{{$t('reward_content')}}</span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a @click="Step(5)" href="javascript:void(0)" :class="{active:Active==5}" data-toggle="tab">
                                                <span class="step">5</span>
                                                <span class="title">{{$t('designated_lottery')}}</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div id="bar" class="progress progress-info" style="width: 75%">
                                        <div class="progress-bar" role="progressbar" aria-valuenow="0"
                                            aria-valuemin="0" aria-valuemax="100" style="width: 0%;"></div>
                                    </div>
                                    <div class="tab-content">
                                        <!-- STEP1 -->
                                        <div id="step1" class="tab-pane fade" :class="{in: In == 1,active: Active == 1}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('select_event_type')}}</label>
                                                                <select v-model="event_type" class="form-control" data-live-search="true" name="type_id">
                                                                    <option :value="null">{{$t('please_select')}}</option>
                                                                    <option v-for="(type,i) in getEventType_list" :key="i" :value="type.value">
                                                                        {{$t('event_type.'+type.parameter)}}
                                                                    </option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="card">
                                                        <div class="card-block">
                                                            <div class="pill-info nav-vertical nav-stacked">
                                                                <ul class="nav nav-pills" role="tablist">
                                                                    <li class="nav-item">
                                                                        <a href="javascript:void(0)" @click="langActive = 0" :class="{active: langActive == 0}" class="nav-link"
                                                                            role="tab" data-toggle="tab">{{$t('default')}}</a>
                                                                    </li>
                                                                    <li v-for="(lang,i) in getLanguages" :key="i" class="nav-item">
                                                                        <a href="javascript:void(0)" @click="langActive=lang.id" :class="{active: langActive == lang.id}"
                                                                            class="nav-link" role="tab"
                                                                            data-toggle="tab">{{lang.name}}</a>
                                                                    </li>
                                                                </ul>
                                                                <div class="tab-content" style="margin-top: 5px">
                                                                    <div role="tabpanel" class="tab-pane fade" :class="{in:langActive == 0,active: langActive == 0}" id="default">
                                                                        <div class="form-group">
                                                                            <p class="mrg-btm-15 font-size-13"><span style="color:red">*</span>{{$t('event_name')}}</p>
                                                                            <input v-model="title" type="text" class="form-control" placeholder="New event" name="title">
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <p class="mrg-btm-15 font-size-13"><span style="color:red">*</span>{{$t('event_content')}}</p>
                                                                            <textarea v-model="content" class="form-control"></textarea>
                                                                        </div>
                                                                    </div>
                                                                    <div v-for="(lang,i) in getLanguages" :key="i" :class="{in:langActive == lang.id,active: langActive == lang.id}" role="tabpanel" class="tab-pane fade">
                                                                        <div class="form-group">
                                                                            <p class="mrg-btm-15 font-size-13">{{$t('event_name')}}</p>
                                                                            <input v-model="langTitle[lang.id]" type="text" class="form-control" placeholder="Taiwan">
                                                                        </div>
                                                                        <div class="form-group">
                                                                            <p class="mrg-btm-15 font-size-13">{{$t('event_content')}}</p>
                                                                            <textarea v-model="langContent[lang.id]" class="form-control"></textarea>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- STPE2 -->
                                        <div id="step2" class="tab-pane fade" :class="{in: In == 2,active: Active == 2}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('select_method')}}</label>
                                                                <select v-model="action_id" class="form-control" id="action_id">
                                                                    <option :value="null">{{$t('please_select')}}</option>
                                                                    <option v-for="(action,i) in getEventAction_list" :key="i" :value="action.value">
                                                                        {{$t('event_action.'+action.parameter)}}
                                                                    </option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label>{{$t('select_stored_value')}}</label>
                                                                <select v-model="order_type_id" :disabled="action_id==2 ||action_id==3" class="form-control" id="order_type_id">
                                                                    <option :value="null">{{$t('please_select')}}</option>
                                                                    <option v-for="(type,i) in getOrderType_list" :key="i" :value="type.id">
                                                                        {{type.name}}</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label>{{$t('select_vendor')}}</label>
                                                                <select v-model="vendor_id" :disabled="action_id==1 ||action_id==4" class="form-control" id="vendor_id">
                                                                    <option :value="null">{{$t('please_select')}}</option>
                                                                    <option v-for="(vendor,i) in getVendor_List" :key="i" :value="vendor.id">
                                                                        {{vendor.name}}</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('select_event_date_range')}}</label>
                                                                <!-- <input type="text" class="form-control" id="range" /> -->
                                                                <vue-datepicker-local v-model="event_range" format="YYYY-MM-DD HH:mm:ss" style="padding:0;width:100%;"/>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('how_many_points_greater_or_equal')}}</label>
                                                                <input v-model="point" @keyup="assign_regex()" type="text" class="form-control allow_numeric" id="point" />
                                                            </div>
                                                        </div>
                                                        <div class="col-md-1">
                                                            <div class="form-group">
                                                                <label>　</label>
                                                                <button @click="add_condition()" type="button" id="add_detail" class="btn btn-info"><i class="ti-plus"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div id="detail" style="padding-bottom:20px">
                                                        <div v-for="(detail,i) in condition_detail" :key="i" class="row">
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="$t('event_action.'+Action_name(detail.action_id))" />
                                                                <input type="hidden" name="action_id[]" value="<?= $row['action_id'] ?>" />
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="Order_type_name(detail.order_type_id)"/>
                                                                <input type="hidden" name="order_type_id[]" value="<?= $row['order_type_id'] ?>" />
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="Vendor_name(detail.vendor_id)" />
                                                                <input type="hidden" name="vendor_id[]" value="<?= $row['vendor_id'] ?>" />
                                                            </div>
                                                            <div class="col-md-3">
                                                                <input type="text" class="form-control" disabled :value="detail.start_time.substr(0,16) +'~'+ detail.end_time.substr(0,16)" />
                                                                <input type="hidden" name="range[]" value="<?= substr($row['start_time'], 0, 16) ?> - <?= substr($row['end_time'], 0, 16) ?>" />
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="detail.point" />
                                                                <input type="hidden" name="point[]" value="<?= $row['point'] ?>" />
                                                            </div>
                                                            <div class="col-md-1">
                                                                <button @click="del_condition(i)" type="button" class="btn btn-info minus"><i class="ti-minus"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- STEP3 -->
                                        <div id="step3" class="tab-pane fade" :class="{in: In == 3,active: Active == 3}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('reward_method')}}</label>
                                                                <select v-model="prize_type_id" class="form-control" data-live-search="true" name="prize_type_id">
                                                                    <option :value="null">{{$t('please_select')}}</option>
                                                                    <option v-for="(type,i) in getPrizeType_list" :key="i" :value="type.id">
                                                                        {{type.name}}</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('reward_name')}}</label>
                                                                <input v-model="prize_name" type="text" class="form-control" name="prize_name"/>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12" style="text-align:center">
                                                            <p class="mrg-top-10 text-dark"> <b><span style="color:red">*</span>{{$t('select_reward_background')}}</b></p>
                                                            <div>
                                                                <label for="img-upload" class="pointer">
                                                                    <img :src="prize_background"
                                                                        id="show_background"
                                                                        style="max-height:300px;max-width:300px" />
                                                                    <input type="hidden" name="background">
                                                                    <span class="btn btn-default display-block no-mrg-btm">Choose file</span>
                                                                    <input @change="upload" class="d-none" type="file" name="background_file" id="img-upload">
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div v-if="prize_is_repeatedly == 1" class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('reward_times')}}</label>
                                                                <input v-model="prize_number" type="text" class="form-control allow_numeric" name="prize_number"/>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('receive_repeatedly')}}</label>
                                                                <div class="col-md-10">
                                                                    <div class="radio radio-inline">
                                                                        <input v-model="prize_is_repeatedly" type="radio" name="prize_is_repeatedly" :value="1" id="r1">
                                                                        <label for="r1">{{$t('can_receive_repeatedly')}}</label>
                                                                    </div>
                                                                    <div class="radio radio-inline">
                                                                        <input v-model="prize_is_repeatedly" type="radio" name="prize_is_repeatedly" :value="0" id="r2">
                                                                        <label for="r2">{{$t('cant_receive_repeatedly')}}</label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-12">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('prize_expiry_date')}}</label>
                                                                <!-- <input type="text" id="date-picker"
                                                                    class="form-control" name="prize_expire_date"
                                                                    value="<?= substr($event['prize_expire_date'], 0, 10) ?>" /> -->
                                                                <vue-datepicker-local v-model="prize_expire_date" format="YYYY-MM-DD HH:mm:ss" style="padding:0;width: auto;"/>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- step4 -->
                                        <div id="step4" class="tab-pane fade" :class="{in: In == 4,active: Active == 4}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-5">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('prize_name')}}</label>
                                                                <input v-model="prizes.name" type="text" class="form-control" id='p_name'/>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('earn_points')}}</label>
                                                                <input v-model="prizes.point" @keyup="assign_regex()" type="text" class="form-control allow_numeric" id='p_point'/>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('win_chance')}}</label>
                                                                <input v-model="prizes.percentage" @keyup="assign_regex()" type="text" class="form-control allow_float" id='p_percentage' />
                                                            </div>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('number_of_prizes')}}</label>
                                                                <input v-model="prizes.number" @keyup="assign_regex()" type="text" class="form-control allow_numeric" id='p_number'/>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-1">
                                                            <div class="form-group">
                                                                <label>　</label>
                                                                <button @click="add_prizes()" type="button" id="add" class="btn btn-info"><i class="ti-plus"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div id="prize" style="padding-bottom:20px">
                                                        <div v-for="(prize,i) in prizes_detail" :key="i" class="row">
                                                            <div class="col-md-5">
                                                                <input type="text" class="form-control" disabled :value="prize.name"/>
                                                                <input type="hidden" name="prize_content_name[]" value="<?= $row['name'] ?>" />
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="prize.point" />
                                                                <input type="hidden" name="prize_content_point[]" value="<?= $row['point'] ?>" />
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="prize.percentage" />
                                                                <input type="hidden" name="prize_content_percentage[]" value="<?= $row['percentage'] ?>" />
                                                            </div>
                                                            <div class="col-md-2">
                                                                <input type="text" class="form-control" disabled :value="prize.number" />
                                                                <input type="hidden" name="prize_content_number[]" value="<?= $row['number'] ?>" />
                                                            </div>
                                                            <div class="col-md-1">
                                                                <button @click="del_prizes(i)" type="button" class="btn btn-info minus"><i class="ti-minus"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- STEP5 -->
                                        <div id="step5" class="tab-pane fade" :class="{in: In == 5,active: Active == 5}">
                                            <div class="row">
                                                <div class="col-md-10 mr-auto ml-auto">
                                                    <div class="row">
                                                        <div class="col-md-7">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('agent')}}</label>
                                                                <select v-model="assign_agent.agent_id" class="item-info form-control" id="assign_agent">
                                                                    <option :value="null">{{$t('please_select')}}</option>
                                                                    <option v-for="(agent,i) in getAgent_List" :key="i" :value="agent.id">
                                                                        {{agent.username}}</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <div class="form-group">
                                                                <label><span style="color:red">*</span>{{$t('winning_rate')}}</label>
                                                                <input v-model="assign_agent.percentage" @keyup="assign_regex()" type="text" placeholder="ex:10" class="form-control allow_float" id="assign_percentage"/>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-1">
                                                            <div class="form-group">
                                                                <label>　</label>
                                                                <button @click="add_assign_agent()" type="button" id="add_assign" class="btn btn-info"><i class="ti-plus"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div id="assign" style="padding-bottom:20px">
                                                        <div v-for="(assign,i) in assign_agent_detail" :key="i" class="row">
                                                            <div class="col-md-7">
                                                                <input type="text" class="form-control" disabled :value="agent_name(assign.agent_id)" />
                                                                <input type="hidden" name="assign_agent_id[]" value="<?= $row['agent_id'] ?>" />
                                                            </div>
                                                            <div class="col-md-4">
                                                                <input type="number" class="form-control allow_float" disabled :value="assign.percentage" />
                                                                <input type="hidden" name="assign_percentage[]" value="<?= $row['percentage'] ?>" />
                                                            </div>
                                                            <div class="col-md-1">
                                                                <button @click="del_assign_agent(i)" type="button" class="btn btn-info minus_assign"><i class="ti-minus"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="wizard-pager">
                                            <div class="">
                                                <button :disabled="Active==1" @click="Previous_Step()" type="button" class="btn btn-default button-previous btn-rounded">{{$t('previousPage')}}</button>
                                                <button :disabled="Active==5" @click="Next_Step()" type="button" class="btn btn-primary button-next pull-right btn-rounded">{{$t('nextPage')}}</button>
                                                
                                                <button @click="save()" type="button" class="btn btn-success pull-right btn-rounded"
                                                    id="save">{{$t('Save')}}</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
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
import VueDatepickerLocal from 'vue-datepicker-local'
export default {
    components: { VueDatepickerLocal },
    computed: {
        ...mapGetters([
            'getLanguages',
            'getEventType_list',
            'getEventAction_list',
            'getVendor_List',
            'getOrderType_list',
            'getPrizeType_list',
            'getAgent_List',
            'getEvent_view']),
    },
    watch: {
        getEvent_view(event) { //編輯活動，將view回來的資料帶入各個input內
            // getEvent_view為vuex取來的值，若直接用getEvent_view做更改動作，系統會判定我們直接更改vuex的state，會見紅
            // vuex深拷貝用法，let EVENT = JSON.parse(JSON.stringify(event)) ，系統將不會認定為是VUEX的data
            let EVENT = JSON.parse(JSON.stringify(event)) 
            this.event_type = EVENT.type_id
            this.title = EVENT.title
            this.content = EVENT.content
            if(EVENT.languages){
                EVENT.languages.forEach((e)=>{
                    this.langTitle[e.language_id] = e.title
                    this.langContent[e.language_id] = e.content
                })
            }
            this.prize_type_id = EVENT.prize_type_id
            this.prize_id = EVENT.prize_id
            this.prize_name = EVENT.prize_name
            this.prize_number = EVENT.prize_number
            this.prize_is_repeatedly = EVENT.prize_is_repeatedly
            this.prize_background = EVENT.prize_background

            this.condition_detail = EVENT.details
            this.assign_agent_detail = EVENT.assign
            this.prizes_detail = EVENT.prizes

            // 到期時間轉換
            let E = EVENT.prize_expire_date.split(' ')[0].split('-')
            let ET = EVENT.prize_expire_date.split(' ')[1].split(':')
            let end = new Date(E[0],E[1] - 1,E[2], ET[0], ET[1], ET[2])
            this.prize_expire_date = end
        },
        action_id(){
            this.order_type_id = null
            this.vendor_id = null
            this.point = null
        },
        
    },
    data() {
        return {
            In: 1, //bind:class用
            Active: 1, //bind:class用
            langActive: 0,
            // 資料
            event_type: null,
            title: null,
            content: null,
            langTitle: [],
            langContent: [],
            // step2
            action_id: null,
            order_type_id: null,
            vendor_id: null,
            event_range: [new Date(),new Date()],
            point: null,
            condition_detail: [],
            // step3
            prize_type_id: null,
            prize_name: null,
            prize_background: null,
            prize_number: 1,
            prize_is_repeatedly: 0,
            prize_expire_date: new Date(),
            // step4
            prizes: {
                name: null,
                point: null,
                percentage: null,
                number: null,
            },
            prizes_detail: [], 
            // step5
            assign_agent: {
                agent_id: null,
                percentage: null,
            },
            assign_agent_detail: [] 
        }
    },
    methods: {
        Step(c){ //更換步驟
            this.In = c
            this.Active = c
        },
        Previous_Step() {//上步驟
            let In = this.In
            let Active = this.Active
            if(In >= 2 && Active >= 2){
                In--
                Active--
                this.In = In
                this.Active = Active
            }
        },
        Next_Step() {//下步驟
            let In = this.In
            let Active = this.Active
            if(In <= 4 && Active <= 4){
                In++
                Active++
                this.In = In
                this.Active = Active
            }
        },
        // step 2
        add_condition() { //新增活動方式項目
            if(this.action_id== null || this.point==null) {
                alert(this.$t('please_enter_content'))
            } else {
                let start = this.event_range[0]
                let end = this.event_range[1]
                let startY = start.getFullYear()
                let startM = start.getMonth() + 1
                let startD = start.getDate()
                let starth = start.getHours()
                let startm = start.getMinutes()
                let starts = start.getSeconds()
                let endY = end.getFullYear()
                let endM = end.getMonth() + 1
                let endD = end.getDate()
                let endh = end.getHours()
                let endm = end.getMinutes()
                let ends = end.getSeconds()
                if(startM < 10){ startM = '0'+startM }
                if(startD < 10) { startD = '0'+startD }
                if(starth < 10) { starth = '0'+starth }
                if(startm < 10) { startm = '0'+startm }
                if(starts < 10) { starts = '0'+starts }
                if(endM < 10){ endM = '0'+endM }
                if(endD < 10){ endD = '0'+endD }
                if(endh < 10){ endh = '0'+endh }
                if(endm < 10){ endm = '0'+endm }
                if(ends < 10){ ends = '0'+ends }
                let startDate = startY+'-'+startM+'-'+startD+' '+starth+':'+startm+':'+starts
                let endDate = endY+'-'+endM+'-'+endD+' '+endh+':'+endm+':'+ends
                
                console.log('start',startDate)
                let newCondition = {
                    action_id: this.action_id,
                    order_type_id: this.order_type_id,
                    vendor_id: this.vendor_id,
                    start_time: startDate,
                    end_time: endDate,
                    point: this.point,
                }
                if(newCondition.action_id==1||newCondition.action_id==4){ // 儲值活動
                    if(newCondition.order_type_id && newCondition.point) {
                        this.condition_detail.push(newCondition)
                        this.action_id = null
                    }
                } else if(newCondition.action_id==2||newCondition.action_id==3){ // 下注 贏分活動
                    if(newCondition.vendor_id && newCondition.point) {
                        this.condition_detail.push(newCondition)
                        this.action_id = null
                    }
                }
            }
            
        },
        del_condition(i) { //刪除活動方法項目
            this.condition_detail.splice(i,1)
        },
        // 建立項目顯示
        Action_name(id) { //顯示名稱:新建立的活動方法項目condition_detail陣列內皆是id，顯示必須是名稱而不是編號，需去getEventAction_list撈出此項目id之名稱
            let ary = this.getEventAction_list.filter(res=>{
                return res.value == id
            })
            let name = ary.length>0 ? ary[0].parameter :  ''
            return name
        },
        Order_type_name(id) { //如上顯示名稱
            let ary = this.getOrderType_list.filter(res=>{
                return res.id == id
            })
            let name = ary.length>0 ? ary[0].name : ''
            return name
        },
        Vendor_name(id) { //如上顯示名稱
            let ary = this.getVendor_List.filter(res=>{
                return res.id == id
            })
            let name =  ary.length>0 ? ary[0].name : ''
            return name
        },
        // step3
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
                    vm.prize_background = base
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
        // step4
        add_prizes() { //新增獎勵項目
            if(
                this.prizes.name==null||
                this.prizes.name==''||
                this.prizes.point==null||
                this.prizes.point==''||
                this.prizes.percentage==null||
                this.prizes.percentage==''||
                this.prizes.number==null||
                this.prizes.number=='') {
                alert(this.$t('please_enter_content'))
            }else {
                let newPrizes = {
                    name: this.prizes.name,
                    point: this.prizes.point,
                    percentage: this.prizes.percentage,
                    number: this.prizes.number,
                }
                if(newPrizes.name &&newPrizes.point &&newPrizes.percentage&& newPrizes.number) {
                    this.prizes_detail.push(newPrizes)
                }
            }
        },
        del_prizes(i) { //刪除獎勵項目
            this.prizes_detail.splice(i,1)
        },
        // step5
        add_assign_agent() { //新增指定開獎項目
            if(this.assign_agent.agent_id==null||this.assign_agent.percentage==''||this.assign_agent.percentage==null) {
                alert(this.$t('please_enter_content'))
            }else {
                let newAssign_agent = {
                    agent_id: this.assign_agent.agent_id,
                    percentage: this.assign_agent.percentage,
                }
                if(newAssign_agent.agent_id && newAssign_agent.percentage) { // 輸入不可為空
                    let ary = this.assign_agent_detail.filter((e)=>{
                        return this.assign_agent.agent_id == e.agent_id
                    })
                    if(!ary.length>0) { // 指定開獎營運商不得重複
                        let sum = 0
                        this.assign_agent_detail.forEach((e)=>{
                            let int = parseInt(e.percentage)
                            sum += int
                        })
                        let addPercent = parseInt(this.assign_agent.percentage)
                        if(sum+addPercent > 100){
                            alert(this.$t('sum_of_winning_percentage_not_over_100%'))
                        } else {
                            this.assign_agent_detail.push(newAssign_agent)
                        }
                    } else {
                        let agent_id = ary[0].agent_id
                        let getName_ary = this.getAgent_List.filter(res=>{
                            return res.id == agent_id
                        })
                        let name =  getName_ary.length>0 ? getName_ary[0].username : ''
                        alert(name +' '+ this.$t('winning_percentage_has_been_set'))
                    }
                }
            }
        },
        del_assign_agent(i) { //刪除指定開獎項目
            this.assign_agent_detail.splice(i,1)
        },
        // 建立項目顯示
        agent_name(id){
            let ary = this.getAgent_List.filter(res=>{
                return res.id == id
            })
            let name =  ary.length>0 ? ary[0].username : ''
            return name
        },
        save(){
            // 多語
            let languages = []
            this.langTitle.forEach((e,key)=>{
                if(e && e!==''){
                    let content = this.langContent[key]
                    let item = {
                        "language_id":key,
                        "title":e,
                        "content":content
                    }
                    languages.push(item)
                }
            })
            // 背景圖
            let prize_background = this.prize_background
            if(prize_background){
                if(prize_background.substr(0,4)=='http'){
                    prize_background = null
                }
            }
            // 到期日
            let end = this.prize_expire_date
            let endY = end.getFullYear()
            let endM = end.getMonth() + 1
            let endD = end.getDate()
            let endh = end.getHours()
            let endm = end.getMinutes()
            let ends = end.getSeconds()
            if(endM < 10){ endM = '0'+endM }
            if(endD < 10){ endD = '0'+endD }
            if(endh < 10){ endh = '0'+endh }
            if(endm < 10){ endm = '0'+endm }
            if(ends < 10){ ends = '0'+ends }
            let expire_date = endY+'-'+endM+'-'+endD+' '+endh+':'+endm+':'+ends
            let data = {
                id: this.$route.query.id,
                type_id: this.event_type, 
                title: this.title,
                content: this.content,
                languages: languages,
                details: this.condition_detail,
                prize_type_id: this.prize_type_id,
                prize_name: this.prize_name,
                prize_background: prize_background,
                prize_number: this.prize_number,
                prize_is_repeatedly: this.prize_is_repeatedly,
                prize_expire_date: expire_date,
                prizes: this.prizes_detail,
                assign: this.assign_agent_detail
            }
            // console.log('data',data)
            if(this.$route.query.id) { //編輯
                this.$store.dispatch('action_event_edit',data)
            } else { //新增 
                this.$store.dispatch('action_event_add',data)
            }
        },
        // 基礎驗證
        assign_regex(){
            // step2
            if(this.point!==null){
                let point = this.point.replace(/[^\d]/g, "")
                this.point = point
            }
            // step4
            if(this.prizes.point!==null){
                let prizes_point = this.prizes.point.replace(/[^\d]/g, "")
                this.prizes.point = prizes_point
            }
            if(this.prizes.percentage!==null){
                let prizes_percentage = this.prizes.percentage.replace(/[^\d]/g, "")
                this.prizes.percentage = prizes_percentage
            }
            if(this.prizes.number!==null){
                let prizes_number = this.prizes.number.replace(/[^\d]/g, "")
                this.prizes.number = prizes_number
            }
            // step5
            if(this.assign_agent.percentage!==null){
                let assign_percentage = this.assign_agent.percentage.replace(/[^\d\.]/g, "")
                this.assign_agent.percentage = assign_percentage
            }
        },

        View(id) {
            if(id) { //編輯
                this.$store.dispatch('action_event_view',id)
            }
            this.$store.dispatch('action_param_list',['event_type','event_action']) //參數列表 - event_type:活動類型, event_action:行為列表
            this.$store.dispatch('action_vendor_list')
            this.$store.dispatch('action_orderType_list')
            this.$store.dispatch('action_prizeType_list','')
            this.$store.dispatch('action_agent_list',{page:1,per_page:1000})
        }
    },
    created() {
        this.View(this.$route.query.id)
    },
}
</script>
<style scoped>
.pill-info .nav-pills > li > a.active.nav-link {
    background-color: #0f9aee!important;
}
.datepicker-range {
    width: auto;
}
</style>