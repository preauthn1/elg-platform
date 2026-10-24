<template>
    <div class="home">
        <Headerbar></Headerbar>
            <div class="bannerB">        
                <el-carousel indicator-position="none" trigge="click" arrow="nerver" >
                    <el-carousel-item v-for="(item,i) in getBanner" :key="i">
                        <div class="bannerImg">
                            <img :src="item.image"  >
                        </div>
                    </el-carousel-item>
                </el-carousel>
            </div>            
            <div class="bannerS">                  
                <el-carousel indicator-position="none" trigge="click" arrow="nerver" >
                    <el-carousel-item v-for="(item,i) in getBanner" :key="i">
                    <div class="bannerImg">
                        <img :src="item.image"  >
                    </div>
                    </el-carousel-item>
                </el-carousel>               
            </div>
            <div class="tagB">
                <span>{{ $store.state.eventLength }}</span>
                <div @click="goEvents()"><img src="../assets/discount.png" border="0" ></div>
            </div>
            <div class="tagS">
                <span>{{ $store.state.eventLength }}</span>
                <div @click="goEvents()"><img src="../assets/YButtonS.png" border="0" ></div>
            </div>
            <!--PC開始-->
        <div class="main mainB" >
            <div class="game-block" v-for="(item, i) in gamesAry" :key="i">
                <h2>{{item.name}}</h2>
                <div class="left_button control" @click="left"><img src="../assets/L.png"></div>
                <div class="right_button control" @click="right"><img src="../assets/R.png"></div>
                <div class="game-slide__wrap" >
                    <div class="game-slide__wrapper">
                        <div v-for="(game,i) in item.games" :key="i">
                            <div style="width:100%" @click="playgame(game)">
                                <div style="width:100%"><img v-bind:src="game.image" style="width:70%; height:160px;"></div>
                                    <div class="Gname" style="width:80%; ">
                                        <el-row style="width:100%;">
                                            <el-col  style="width:80%" class="Gfont" >
                                                {{ game.name }}
                                                <span>{{ game.lottery_pool }}</span></el-col>
                                        </el-row>
                                    </div>
                                </div>
                            <div v-if="favoriteVisible" class="addFavorite" @click="addFavorite(game)"><img v-if="game.is_favourite" src="../assets/H1.png"><img v-else src="../assets/H2.png"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div> 
        <!--手機開始-->
        <div class="main mainS" >
            <div class="game-block" v-for="(item, i) in gamesAry" :key="i">
                <h2>{{item.name}}</h2>
                <div class="game-slide__wrap" >
                    <div class="game-slide__wrapper">
                        <div v-for="(game,i) in item.games" :key="i">
                            <div style="width:100%" @click="playgame(game)">
                                <div class="gameImg"><img v-bind:src="game.image" ></div>
                                    <div class="Gname" style="width:85%; ">
                                        <el-row style="width:100%;">
                                            <el-col  style="width:80%" class="Gfont" >
                                                {{ game.name }}
                                                <span>{{ game.lottery_pool }}</span></el-col>
                                        </el-row>
                                    </div>
                                </div>
                            <div v-if="favoriteVisible" class="addFavorite" @click="addFavorite(game)"><img src="../assets/H2.png"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>        
    </div>    
</template>


<script>
// import $ from 'jquery'
import Headerbar from '@/components/header_bar'
import { mapGetters, mapActions } from 'vuex'

// import Axios from 'axios';
// import secret from '../utils/secret'
// const md5 = require('js-md5');
export default {
    components:{
		Headerbar,
	},
    computed: {
        ...mapGetters({
        getBanner: 'getBanner',
        menuType: 'getMenu',
        menuTypeid: 'getMenu_id',
        gameList: 'getGamelist',////舊版gamesary 寫死
        gamesAry: 'getGamesAry',////新版gamesary v-for
        reality: 'getReality',
        slot: 'getSlot',
        poker: 'getPoker',
        lottery: 'getLottery',
        fishing: 'getFishing',
        complex: 'getComplex',

        }),
    },
    data () {
        return {
            img: require('../assets/logo1.png'),
            favoriteVisible: false,
        }
    },
    mounted() {
        this.$store.dispatch('actionHomegames')
        this.$store.dispatch('actionBanner',1)
        this.$store.dispatch('actionMygames')
        this.$store.dispatch('getVendors')
        this.$store.dispatch('actionEvents')///取專屬優惠項次 //
        this.Visible()
    },
    methods: {
        ...mapActions([
            'actionTypeid',
            'addFavorite',
        ]),
        goEvents() {
            let token = [REDACTED]'token')
            if(token){
                this.$router.push('/events')
            } else {
                alert('請先登入')
            }
        },
        playgame(game) {
            let token = [REDACTED]'token')
            if(token) {
                this.$store.dispatch('getBalance', game)
            } else {
                alert('請先登入')
            }
        },
        right(e) {
            let width = $('.game-slide__wrap').width()
            let slide_el = e.currentTarget.nextElementSibling
            $(slide_el).animate({
                scrollLeft: width
            },500)
        },
        left(e) {
            let slide_el = e.currentTarget.nextElementSibling.nextElementSibling
            $(slide_el).animate({
                scrollLeft: 0
            },500)
        },
        Visible() {
            let token = [REDACTED]'token')
            if(token) {
                this.favoriteVisible = true
            }
        }
    },

    setSize: function () {
        this.bannerHeight = 740 / 2560 * this.screenWidth
        if(this.bannerHeight > 740) this.bannerHeight = 740
        if(this.bannerHeight < 360) this.bannerHeight = 360
        },
}
</script>

<style lang="scss">

.home{
    padding-top:120px;
    margin:0 auto;
    width:100%;
    font-family: "Helvetica", "Arial","LiHei Pro","黑體-繁","微軟正黑體", sans-serif;
}

.bannerImg{
    width: 100%;
    height:auto;    
    overflow:hidden;   
    text-align:center;
    
}
.bannerB{
    display:block;
}
@media screen and(max-width: 650px){
    .bannerB{
    display:none;
}
    }
.bannerS{
    display:none;
}
@media screen and(max-width: 650px){
    .bannerS{
    display:block;
    margin-bottom:-190px;
}
    }
.tagB{
    position:fixed;
    top:200px;
    right:0px;
    z-index:500;
    display:block;
    span {
        position: absolute;
        top: 50%;
        transform: translateY(-55%);
        left: 40px;
        font-size: 1.5rem;
    }
}
@media screen and(max-width:650px) {
    .tagB{
        display:none;            
}
}
.tagS{
    position:fixed;
    top:240px;
    right:0px;
    z-index:500;
    display:none;
}
@media screen and(max-width:650px) {
    .tagS{
        display:block;
        span {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            left: 25px;
            font-size: 1.5rem;
        } 
    }
}
@media only screen and(max-width:768px){
    .home{
        padding-top:px;    
    }
}
@media only screen and(max-width:650px){
	.home{padding-top:94px;    }
}

@media only screen and (max-width: 768px) {
    .banner_img{height:183px;}
}
.listitem{
    padding-top:15px;
    display: flex;
    width:50%;
    text-align:center;
    flex-wrap: wrap;
    margin:0 auto;
}
.listtableall{
    width:100%;
}
.listtable{
    background-color:#c8e2ea;
    color:#666;
    padding:5px;
    margin:2px;
}
.mainB{
    display:block;
}
@media screen and (max-width: 650px) {
    .mainB{display:none;}
    
}
.mainS{
    display:none;
    
}
@media screen and (max-width: 650px) {
    .mainS{
        display:block;  
        }
    
}
.main {
    margin: auto;
    padding-top:20px;
    text-align: center;
    margin:0 auto;
    width:95%;
    .game-block {
        position: relative;      
        margin-bottom:40px;
        h2{
            font-size: 20px;
            margin:5px;
        }
        .game-slide__wrap {
        margin:0 auto; 
        width:80%;
        // overflow-x: scroll;
        overflow: hidden;
            .game-slide__wrapper {
                display: flex;
                flex-wrap: wrap;
                width: 167%;
                .gameImg{
                    width:100%;
                    img{
                        width:100%;
                    }
                }
                div {
                    width: calc(20% / 2);
                    position: relative;
                    .addFavorite {
                        position: absolute;
                        bottom: 0px;
                        right: 30px;
                        z-index: 99;
                    }
                    @media screen and (max-width: 650px) {
                        .addFavorite{
                            bottom:0px;                    
                            right: 15px;
                        }                        
                    }
                }
            }
        }

        .control {
            position: absolute;
            display: block;
            width: 50px;
            height: 50px;
            text-align: center;
            line-height: 50px;
            cursor: pointer;
        }
        .right_button {
            top: 50%;
            transform: translateY(-50%);
            right: 20px;
        }
        .left_button {
            top: 50%;
            transform: translateY(-50%);
            left: 20px;
        }
    }

.Gname {
    margin: auto;
    text-align:center;
    line-height: 25px;
    height:30px;    
    background-color:#647abc69;    
    position: relative;    
    overflow: hidden;
}

.Gfont{
    
    padding-top:3px;
    font-size:14px;
 }
  
 
}
@media screen and (max-width: 650px){
    .main {
    margin: auto;
    padding-top:20px;
    text-align: center;
    margin:0 auto;
    width:85%;
    .game-block {
        position: relative;       
        .game-slide__wrap {
        margin:0 auto; 
        width:100%;
        // overflow-x: scroll;
        overflow: hidden;
            .game-slide__wrapper {
                display: flex;
                flex-wrap: wrap;
                width: 340%;
                div {
                    width: calc(30% / 2);
                }
            }
        }
    }
        .Gname{
            margin: auto;
            text-align:center;
            line-height:26px;
            height:30px;
            overflow: hidden;    
            background-color:#647abc69;
            }
        .Gfont{
            
            padding-top:3px;
            font-size:14px;
        }
 }
}
.bottom{
    padding-bottom:50px;
 }
</style>