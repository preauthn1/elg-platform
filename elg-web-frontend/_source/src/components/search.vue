<template>
    <div class="home">
        
        <div  class="search">
        <span>==============搜尋結果============</span>
       
         </div>
         
        <div class="main" >
               <div class="gameItem " v-for="item in gameList" :key="item.id">
                <div @click="getBalance(item)" >
                    <img v-bind:src="item.image" alt="">
                    <h6>{{ item.name }}</h6>
                    <span>{{ item.lottery_pool }}</span>
                </div>
            </div>
        </div>
    </div>


</template>


<script>



import { mapGetters, mapActions } from 'vuex'

// import Axios from 'axios';
// import secret from '../utils/secret'
// const md5 = require('js-md5');
export default {
    computed: {
        ...mapGetters({
        menuType: 'getMenu',
        menuTypeid: 'getMenu_id',
        gameList: 'getGamelist',
        }),
    },


    data() {
        return {
            // md5_key: '&zSMvVTK4gbDvCcU',
            // page: 1,
            // per_page: 16,
            // games:[]
        }
    },
    mounted() {
        this.$store.dispatch('actionHomegames')
        this.$store.dispatch('actionBanner',1)
    },
    methods: {
        ...mapActions([
            'actionTypeid',
            'getBalance',
            'actionBanner'
        ]),

      
    },
    
}
</script>

<style lang="scss">

.home{
    padding-top:140px;
    margin:0 auto;
   width:100%;
   
    
}

@media only screen and(max-width:768px){
		.home{padding-top:120px;    
}
    }
@media only screen and(max-width:650px){
		.home{padding-top:140px;    
}
	}

.search{
    
    margin:0 auto;
    height:50px;
    text-align:center;
    color:#fff;
}
.main {
    
    display: flex;
   width:75%;
    text-align:center;
    flex-wrap: wrap;
    margin:0 auto;
    
    .gameItem {
        width: 230px;
        img {
            width: 100%;
            vertical-align: top;
        }
        h6 {
          margin: 0;
          padding-bottom: 15px;  
          text-align: center;
        }
    }
    
 
     
    
   
}

</style>