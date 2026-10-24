<template>
    <div class="events" >
        <div class="events__wrap" style="margin-bottom:20px;">
            <div v-for="item in getEvents" :key="item.id">
                <div class="events__title"><h2>{{ item.title }}</h2></div>
                <div class="form__wrap">
                    <p class="form_font">
                        {{ item.content }}
                    </p>
                    <p class="form_font">
                        開始時間: {{ item.detail[0].start_time }} ~ {{ item.detail[0].end_time }}
                    </p>
                    <p class="form_font">
                        兌換截止日: {{ item.expire_date }}
                    </p>
                    <p class="form_font">
                        可兌換次數: {{ item.count }}
                    </p>
                    <el-button @click="exchange(item)">兌換</el-button> 
                    <el-button >兌獎記錄</el-button> 
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { mapGetters, mapActions } from 'vuex'
export default {
    computed: {
        ...mapGetters({
            getEvents: 'getEvents',
        })
    },
    mounted() {
        this.$store.dispatch('actionEvents')
    },
    methods: {
        exchange(item) {
            let token = [REDACTED]'token')
            let type = item.type
            let id = item.id
            let expire_ery = item.expire_date.split("-",3)
            let expire_year = parseFloat(expire_ery[0])
            let expire_month = parseFloat(expire_ery[1])
            let expire_date = parseFloat(expire_ery[2].split(" ",2)[0])
            let year = new Date().getFullYear()
            let month = new Date().getMonth() + 1
            let date = new Date().getDate()
            console.log(item)
            console.log(expire_year)
            if(expire_year >= year) {
                if(expire_month >= month) {
                    if(expire_date >= date) {
                        if(type == 1) {
                            window.location.href = "https://example.invalid/games/Scratch/index.php?token="+ token +"&id="+ id
                        } else if (type == 2) {
                            window.location.href = "https://example.invalid/games/Roulette/index.php?token="+ token +"&id="+ id
                        }
                    } else {
                        alert('過期')
                    }
                } else {
                    alert('過期')
                }
            } else {
                alert('過期')
            }
        }
    }
}
</script>

<style lang="scss">
.events {
   width: 100%;
   padding-top:150px;
    .events__wrap {
        width: 80%;
        margin: auto;
        background-color: #fff;
        font-size: 12px;
        padding-bottom:20px;
        .events__title {
            color: #fff;
            padding: 5px 0;
            h2 {
                background-color: #4d7992;
                padding: 8px 30px;
                font-size: 18px;
                transform: translateX(30px)
            }
        }
        .events__subTitle {
            color: #fff;
            text-align: center;
            background-color: #4d7992;
            margin: 0 30px;
        }
        .form__wrap {
            margin: 30px;
            padding:20px;
            background-color: #eee;
            text-align:center;
            color:#666;
        }
        .form_font {
            margin: 8px;
            text-align:left;
            color:#666;
            font-size:15px;
            line-height:30px;
            font-weight:bold;
        }
        @media only screen and (max-width:650px) {
            .form__wrap{ 
                padding:20px 0 ; 
            }
        } 
        .form__width {
            width: 100%;
        }
        @media only screen and (max-width:768px) {
            .form__width {
                width: 90%;
            }   
        }
        @media only screen and (max-width:650px) {
            .form__width {
                width: 100%;
            } 
        } 
    }
}


</style>

