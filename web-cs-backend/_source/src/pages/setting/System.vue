<template>
    <div id="pageTable">
        <v-container grid-list-xl fluid>
            <v-layout row wrap>
                <v-flex lg12>
                    <v-card>
                        <v-card-text>
                            <v-container grid-list-md>
                                <v-layout wrap>
                                    <v-flex xs12>
                                        <v-text-field v-model="roomName" :label="$t('form.room_Name')"></v-text-field>
                                    </v-flex>
                                    <v-flex xs12>
                                        <h5>{{ $t('form.room_BGImage') }}</h5>
                                        <img :src="roomBGI" style="width:250px;vertical-align:bottom;"/>
                                        <!-- <img src="/static/avatar/man_4.jpg"/> -->
                                        <input type="file" @change="uploadImg">
                                    </v-flex>
                                    <v-flex xs12>
                                        <v-text-field v-model="roomBGC" :label="$t('form.room_BGColor')"></v-text-field>
                                        <!-- <div id="cp3" class="input-group colorpicker-component">
                                            <input type="text" value="#00AABB" class="form-control" />
                                            <span class="input-group-addon"><i></i></span>
                                        </div> -->
                                    </v-flex>
                                    <v-flex xs12>
                                        <v-text-field v-model="headerBGC" :label="$t('form.header_BGColor')"></v-text-field>
                                    </v-flex>
                                    <v-flex xs12>
                                        <v-text-field v-model="headerText" :label="$t('form.header_fontColor')"></v-text-field>
                                    </v-flex>
                                    <v-flex xs12>
                                        <v-text-field v-model="apiUrl" :label="$t('form.api_url')"></v-text-field>
                                        <router-link to="/file/api">{{ $t('form.api_document') }}</router-link>
                                    </v-flex>
                                </v-layout>
                            </v-container>
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer></v-spacer>
                            <v-btn color="info darken-1" @click="submit()">{{ $t('form.save') }}</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-flex>
            </v-layout>
        </v-container>
    </div>
</template>
<script>
import $ from '@/../static/js/jquery-3.4.1.min.js'
import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['get_SystemSetting']),
        roomName:{ // 用computed來當v-model 變更store.state
            get() { // 取state
                return this.get_SystemSetting.name
            },
            set(val) { //變更state值
                this.$store.commit('roomName',val)
            }
        },
        roomBGI:{
            get() {
                return this.get_SystemSetting.background_image
            },
            set(val) { 
                this.$store.commit('roomBGI',val)
            }
        },
        roomBGC:{
            get() {
                return this.get_SystemSetting.background_color
            },
            set(val) { 
                this.$store.commit('roomBGC',val)
            }
        },
        headerBGC:{
            get() {
                return this.get_SystemSetting.header_background_color
            },
            set(val) { 
                this.$store.commit('headerBGC',val)
            }
        },
        headerText:{
            get() {
                return this.get_SystemSetting.header_font_color
            },
            set(val) { 
                this.$store.commit('headerText',val)
            }
        },
        apiUrl:{
            get() {
                return this.get_SystemSetting.api_url
            },
            set(val) { 
                this.$store.commit('apiUrl',val)
            }
        }
    },
    data () {
        return {
            
        };
    },
    methods: {
        uploadImg(e) { //上傳圖片
            let file = e.target.files[0]
            let reader = new FileReader()
            let vm = this
            reader.readAsDataURL(file)
            reader.onload = function() {
                vm.roomBGI = this.result
            }
        },
        submit() {
            let setting = {
                "roomName":this.roomName,
                "roomBGC":this.roomBGC,
                "headerBGC":this.headerBGC,
                "headerText":this.headerText,
                "apiUrl":this.apiUrl,
            }
            if(this.roomBGI){
                let https = this.roomBGI.split(':')
                if(https[0]!=='https') { //圖片傳api必須為64base才送出，不然會黨，那api抓回來為圖片都是https開頭，所以當圖片沒重新上傳(64base)，就不給他更新圖片
                // 上方SETTING物件加上上傳圖片(64base)
                    setting["roomBGI"] = this.roomBGI
                }
            }
            this.$store.dispatch('action_SystemSave',setting)
        }
    },
    created() {
        this.$store.dispatch('action_SystemView')
    },
    mounted() {
        // $('#cp3').colorpicker({
        //     color: '#AA3399',
        //     format: 'rgb'
        // });
    }
};
</script>
