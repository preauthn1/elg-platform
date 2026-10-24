<template>
    <footer>
		<div class="footerMB">
        <el-row class="footer-main" type="flex" justify="center">
			<el-col :span="24" >
				<div class="footer-con">
				<el-row style="padding: 15px 0px;">
					<el-col :span="3">
						<div>
							<h5>{{ $t("member") }}</h5>
							<h5>{{ $t("memberSignIn") }}</h5>
							<h5>{{ $t("forget") }}</h5>
							<h5>{{ $t("memberSignUp") }}</h5>
							<h5>{{ $t("specification") }}</h5>
						</div>				
					</el-col>
					<el-col :span="3">
						<div>
							<h5>{{ $t("realGame") }}</h5>
							<h5>{{ $t("poker") }}</h5>
							<h5>{{ $t("electronicGame") }}</h5>
							<h5>{{ $t("lottery") }}</h5>
						</div>
					</el-col>
					<el-col :span="3">
						<div>
							<h5>{{ $t("placement") }}</h5>
							<h5>{{ $t("sponsor") }}</h5>
							</div>
					</el-col>
					<el-col :span="3"><div><h5>{{ $t("newGame") }}</h5></div></el-col>
					<el-col :span="3"><div><h5>{{ $t("favorite") }}</h5><h5>{{ $t("often") }}</h5><h5>{{ $t("frequently") }}</h5><h5>{{ $t("Win") }}</h5><h5>{{ $t("Lose") }}</h5></div></el-col>
					<el-col :span="3"><div><h5>{{ $t("hotGame") }}</h5></div></el-col>
					<el-col :span="5"><img src="../assets/logo2.png" class="logoimg"></el-col>
				</el-row></div>
			</el-col>
            
        </el-row>
		</div>
		<div class="footerSB">
		<el-row class="footer-main" type="flex" justify="center">
			<el-col :span="24" >
				<div class="footer-conS">
				<el-row style="padding: 15px 0px;">
					<el-col :span="8">
						<div>
							<h5>{{ $t("member") }}</h5>
							<h5>{{ $t("memberSignIn") }}</h5>
							<h5>{{ $t("forget") }}</h5>
							<h5>{{ $t("memberSignUp") }}</h5>
							<h5>{{ $t("specification") }}</h5>
						</div>				
					</el-col>
					<el-col :span="8">
						<div>
							<h5>{{ $t("realGame") }}</h5>
							<h5>{{ $t("poker") }}</h5>
							<h5>{{ $t("electronicGame") }}</h5>
							<h5>{{ $t("lottery") }}</h5>
						</div>
					</el-col>
					<el-col :span="8">
						<div>
							<h5>{{ $t("placement") }}</h5>
							<h5>{{ $t("sponsor") }}</h5>
							</div>
					</el-col>			
			
				</el-row>
				<el-row style="padding: 15px 0px;">
					<el-col :span="8"><div><h5>{{ $t("newGame") }}</h5></div></el-col>
					<el-col :span="8"><div><h5>{{ $t("favorite") }}</h5><h5>{{ $t("often") }}</h5><h5>{{ $t("frequently") }}</h5><h5>{{ $t("Win") }}</h5><h5>{{ $t("Lose") }}</h5></div></el-col>
					<el-col :span="8"><div><h5>{{ $t("hotGame") }}</h5></div></el-col></el-row>
				</div>
			</el-col>            
        </el-row>
		</div>
		<div class="footerB">
			<el-row class="footer-sub">
				<div class="fs-font">
					<el-col :span="3"  ><h5>{{ $t("help") }}</h5></el-col>
					<el-col :span="3" ><h5>{{ $t("contact") }}</h5></el-col>
					<el-col :span="3"><h5>{{ $t("rule") }}</h5></el-col>
					<el-col :span="3"><h5>{{ $t("privacy") }}</h5></el-col>
					<el-col :span="3"><h5>{{ $t("cookies") }}</h5></el-col>
					<el-col :span="3" :offset="3"><h5 style="width: 225px;">Copyright(C) XSG Casino Co.Ltd.</h5></el-col>
				</div>
			</el-row>
		</div>
		<div class="footerS">
			<el-row class="footer-sub">
				<div class="fs-font">
					<el-col :span="2" ><h5>{{ $t("help") }}</h5></el-col>
					<el-col :span="5" ><h5>{{ $t("contact") }}</h5></el-col>
					<el-col :span="5"><h5>{{ $t("rule") }}</h5></el-col>
					<el-col :span="6"><h5>{{ $t("privacy") }}</h5></el-col>
					<el-col :span="6"><h5>{{ $t("cookies") }}</h5></el-col>					
				</div>
			</el-row>
			<el-row class="footer-sub">
				<div class="fs-font">
				<el-col :span="24" ><h5>Copyright(C) XSG Casino Co.Ltd.</h5></el-col>
				</div>
			</el-row>
		</div>

    </footer>
</template>
<script>
export default {
	name: "app",
	data() {
		return {
		}
	}
}
</script>

<style lang="scss" scoped>
footer {
	text-align: center;
	width: 100%;
	h6 {
		line-height: 5px;
		color:#aaa;
	}
	color: #fff;
.footerB{
	display:block;
}
@media screen and(max-width:650px){
	.footerB{display:none;}
}
.footerS{
	display:none;
	
}
@media screen and(max-width:650px){
	.footerS{display:block;}
}
	.footer-main {		
		background-color: #081b26;		
	.logoimg{
		padding-top:20px;
	}
	
	.footer-con
	{
		width:70%;
		margin:0 auto;
	}
	@media only screen and(max-width:768px){
		.footer-con
	{
		width:100%;
		margin-left:25px;
	}
	}
		ul {
			list-style: none;
			padding-inline-start: 0;
			
			font-size: 12px;
			li {
				padding-bottom: 5px;
				color: #aaa;
			}
		}
	}
	
	.footer-sub  {
		background-color: #151c21;
		margin:0 auto;
		padding: 10px 0;
		
	}
	.fs-font{
		width:90%;
		margin:0 auto;
	}
.footerMB{
	
		background-color: #081b26;
		padding-top:15px;
		display:block;
}
@media screen and(max-width:650px){
	.footerMB{display:none;}
}
.footerSB{
	
		background-color: #081b26;
		display:none;
}
@media screen and(max-width:650px){
	.footerSB{
		display:block;
		padding-top:15px;
	}
}
	
	

.footer-conS
	{
		width:100%;
		margin:0 auto;
	}


}
</style>