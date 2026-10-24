<template>
<div class="allBg" >
	<Header></Header>
	<router-view></router-view>
	<Footer></Footer>
</div>

</template>

<script>
import Header from '@/components/header'
import Headerbar from '@/components/header_bar'
import Main from '@/components/main'
import Footer from '@/components/footer'
import secret from '../utils/secret'
const md5 = require('js-md5');
import axios from 'axios'
import store from '../store'
export default {
	name: "app",
	data() {
		return { langs: ["Chinese", "English"] };
	},
	components:{
		Header,
		Headerbar,
		Main,
		Footer,
	},
	methods: {
		template() {
			let jsonMsg = JSON.stringify({
				"domain": window.location.hostname
			})
			let params = secret.Encrypt( jsonMsg )
			let key = md5( params + '&zSMvVTK4gbDvCcU' )
			axios.post('https://example.invalid/api/template',{
				params : params,
				key : key
			},{
				headers: { 'Accept-Language': store.state.lang ,'Authorization': "Bearer " }
			})
			.then(res => {
				if(res.data.data.name == 'v1') {
					$('.allBg').css('background-image','url(../assets/BGPicW.png)')
				}
			})
			.catch(error => {
				console.log(error)
			})
		}
	},
	mounted() {
		this.template()
	}
};
</script>

<style lang="scss">
body::-webkit-scrollbar {
    display: none;
}
body {
    -ms-overflow-style: none;
	padding-right: 0 !important;
}
.allBg{
	background-image:url(../assets/backgroundPic.png);
	color: #fff;
	font-family: "Helvetica", "Arial","LiHei Pro","黑體-繁","微軟正黑體", sans-serif;
}
.fixed-width {
	margin: auto;
}
</style>
