<template>
<div class="home">
    <Headerbar></Headerbar>
    <div class="bannerB">
        <el-carousel arrow="nerver" indicator-position="none" trigge="click"  style="text-align:center;" >
            <el-carousel-item v-for="(item, i) in getBanner" :key="i">
                <div class="bannerImg">                            
                    <img :src="item.image" class="bannerpic">
                </div>
            </el-carousel-item>
        </el-carousel>
    </div>
    <div class="bannerS">
        <el-carousel arrow="nerver"  indicator-position="none" trigge="click" style="text-align:center;" >
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
    <div class="gametype">

        <div class="sub">
            <div class="tagall">
                <div class="tagT" ><img src="../assets/tagT.png"></div>
                <div class="NtagC" style="">
                    <div class="tagitem"  >                            
                        <el-tag>分類</el-tag>
                            <el-checkbox-group v-model="check_gameType">
                                <div v-for="(item, i) in menuType" :key="i">
                                    <el-checkbox :label="item.id"  >{{ item.name }}</el-checkbox>
                                </div>
                            </el-checkbox-group>
                    </div>
                    <div class="tagitem" >
                            <el-tag>遊戲商</el-tag>
                        <div>
                            <el-checkbox label="AG"  ></el-checkbox> 
                        </div>
                        <div>
                            <el-checkbox label="MG" ></el-checkbox> 
                        </div>
                    </div>
                    <div class="tagitem" >
                        <el-tag>我的分類</el-tag>
                            <div><el-checkbox label="我的最愛"  ></el-checkbox></div>
                            <div><el-checkbox label="最常遊玩" ></el-checkbox></div>
                            <div><el-checkbox label="遊玩次數最多" ></el-checkbox></div>
                            <div><el-checkbox label="贏局最多" ></el-checkbox></div>
                            <div><el-checkbox label="輸局最多" ></el-checkbox></div>
                    </div>                        
                </div>
                <div class="tagT" style="margin:0;padding:0;font-size: 0"><img src="../assets/tagB.png"></div>
            </div> 
        </div>
        <div class="main">
            <div v-for="item in gameList" :key="item.id"  class="gamepic">
                    <div @click="playgame(item)">
                        <div class="Gitem"><img v-bind:src="item.image" width="80%" ></div>
                        <div class="Gname" >
                            <h6>{{ item.name }}</h6>
                            <span>{{ item.lottery_pool }}</span>
                        </div>
                    </div> 
                <div class="addFavorite" @click="addFavorite(game)"><img src="../assets/H2.png"></div>
            </div>
                           
                           
        </div> 
     </div>     
</div>

</template>

<script>

import Headerbar from '@/components/header_bar'
import { mapGetters, mapActions } from 'vuex'
export default {
    components:{
		Headerbar,
	},
    computed: {
        ...mapGetters({
            getBanner: 'getBanner',
            menuType: 'getMenu',
            gameList: 'getGamelist',
            vendorBalance: 'getBalance',
            vendors: 'getVendors',
        }),
         transferTo: {
            get () {
                console.log(this.$store.state.balanceForm.transferTo);
                return this.$store.state.balanceForm.transferTo

            },
            set (val) {
                console.log(val);
                this.$store.commit('updateTransferTo',val)
            }
        },
        point: {
            get () {
                return this.$store.state.balanceForm.point
            },
            set (val) {
                this.$store.commit('updatePoint',val)
            }
        },
        check_gameType: {
            get () { return this.$store.state.checkType.gameType },
            set (val) { this.$store.commit('gemeType',val) }
        },
        check_vendorType: {
            get () { return this.$store.state.checkType.vendorType },
            set (val) { this.$store.commit('vendorType',val) }
        },
        check_myType: {
            get () { return this.$store.state.checkType.myType },
            set (val) { this.$store.commit('myType',val) }
        },
    },
    data() {
        return {
            eventLength: sessionStorage.getItem('event_lenght'),
        }

        
    },
   
    mounted() {
        this.$store.dispatch('getVendors')
        this.$store.dispatch('actionBanner',2)
        this.$store.dispatch('actionTypeid')///頁面重整取得game_type//
        this.$store.dispatch('actionEvents')///取專屬優惠項次 //
    },
    methods: {
        ...mapActions({

        }),
        goEvents() {
            let token = [REDACTED]'token')
            if(!token){
                alert('請先登入')
            } else {
                this.$router.push('/events')
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
    },
     created() {
        this.$store.dispatch('getVendors')
        this.$store.dispatch('getAGbalance')
        this.$store.dispatch('getJokerbalance')
        this.$store.dispatch('getSCRbalance')
        this.$store.dispatch('getGDbalance')
    }
    
}
</script>

<style lang="scss">
.home{
    padding-top:120px;    
    font-family: "Helvetica", "Arial","LiHei Pro","黑體-繁","微軟正黑體", sans-serif;
    /deep/ .el-dialog {
		z-index: 8;
		background-color:#304658;
		border-radius:5%;
		box-shadow:#888 3px 3px 3px;
		.el-dialog__body {
			/deep/ .el-form-item__label {
				color: #fff;
				font-size:15px;
			}
			canvas {
				display: block;
				background-size: cover;
				margin: auto;
			}
		}
		
		.el-dialog__footer {
			.el-button {
				margin: 2 auto;
				border: 1px solid #fff;
				color: #fff;
			}
		}
	}
}
.addpoint{
		margin:auto;
		width:45%;
        h3{
            font-size:18px;
            font-weight: bold;
            line-height:30px;
        }
        p{
            font-size:15px;
            line-height:30px;

        }
        div{
            padding:10px;
            text-align:center;
        }
	}
@media only screen and (max-width: 650px) {
    .addpoint{		
		width:120%;
		border:none;
		font-size:0px;	
        margin-right:-40px;
	}
	/deep/ .el-dialog {
		z-index: 8;
		background-color:#304658;
		border-radius:5%;
		box-shadow:#888 3px 3px 3px;		
		}
}
	
.bannerImg{
    width: 100%;
    height:auto;    
    overflow:hidden;   
    text-align:center;    
}
.bannerB{
    display:block;
    margin-bottom:30px;
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
    margin-bottom:-200px;
}
    }

.NtagC{
    width:96%; 
    background-color:#215879;
    margin:0;
    padding:0;
    font-size: 0 
}
.tagall{
    display:block;
    margin-top:40px;
    z-index:500;
}
.tagT{
    margin:0;
    padding:0;
    font-size:0;
}
.tagitem{
    padding-left:15px;
     padding-bottom:10px;
}
.tagB{
    position:fixed;
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
@media screen and(max-width:650px) {
    .tagall{display:none;}
    
}
.el-tag{
    border:none;    
    background-color:#215879;
    font-size:18px;
    color:#fff;
}
@media only screen and(max-width:768px){
		.home{padding-top:76px;    
}
    }
@media only screen and(max-width:650px){
    .home{padding-top:96px;    
}
	}
.gamepic{
    width:15%;
    position: relative;    
    .addFavorite{
        position: absolute;
        bottom:-3px;
        right: 18px;
        z-index: 99;
    }
     @media screen and (max-width: 650px) {
        .addFavorite{
            bottom:-3px;                    
            right: 10px;
        }                        
    }
    }
@media screen and (max-width:650px) {
    .gamepic{
        width:50%;
        padding-bottom:0px;
    }
    
} 
.el-checkbox{
    color:#fff;
}    
.gametype {
    display: flex;
    margin-bottom:200px;
    .main {
    display: flex;
    justify-content: start;
    margin:0 auto;
    text-align:center;
    flex-wrap: wrap;
    width:88%;           
        .filter {
            width: 150px;
            position: absolute;
            top: 50px;
            left: 10px;
            .el-checkbox {
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                color: #fff;
            }
        }
        
    }    
.tagC{
    background:url(../assets/tagC.png);
    border:#fff 2px solid;   
}
.Gitem{
    margin-top:5px;
    margin-bottom:-10px;
} 

.Gname{
    margin: auto;
    text-align:center;
    height:30px;      
    line-height: 30px;
    background-color:#647abc69;    
    width:90%; 
    overflow: hidden;   

}
@media screen and (max-width: 650px) {
    .Gname{
        margin-top:13px;
        height:30px; 
        text-align:left;
        padding-left:3px;
        line-height:30px;
        overflow: hidden;
       
}
}

}


</style>