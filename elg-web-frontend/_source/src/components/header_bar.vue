<template>
<div class="headerBarAll">

 <div class="header-bar" >
        <div class="marquee-wrap">
            <marquee behavior="behavior">The Moon Lady 564241  The Storm 461596 Alice's Adventures 9467278 KingArthur 483213 Alibaba 374852 Diamond Queen 4855672</marquee>
        </div>
        <!--<div class="headerbar-right" style="background-color: #2a3947">
            <el-select v-model="defaultValue" size="small" class="default" popper-class="select__bgc">
                <el-option v-for="item in options" :key="item.value" :value="item.label" v-model="item.label">{{item.label}}</el-option>
            </el-select>
            <div class="array"> <router-link to="/signup"   ><img src="../assets/dark_01.png" border="0"  @click="sinup()"></router-link></div>
            <div class="array"><router-link to=""   ><img src="../assets/dark_02.png" border="0" ></router-link></div>
        </div>-->
    </div>


</div>
   
</template>

<script>
export default {
    data(){
        return {
            options: [{
                    value: '1',
                    label: 'default1'
                },{
                    Value: '2',
                    label: 'default2'}
                ],
            defaultValue: 'Default',
        }
    }
}
</script>

<style lang="scss" scoped>
.headerBarAll{
    
    width:100%;
}
@media screen and (max-width: 650px) 
{
.headerBarAll{
    padding-top:13px;
}    
}

.header-bar {
    color: #fff;
    background-color: #151c21;
    display: flex;
    justify-content: flex-end;
    width:80%;
    margin: 0 auto;
    
    
    .marquee-wrap {
       
        marquee {
            padding: 12px 0 8px;
        }      
    }
    @media screen and(min-width: 650px){
        .marquee-wrap{
            width: calc(100% - 202px);
        }
        
    }
    .default {
    margin: 5px 0;
    padding: 0px 5px;
    border-right: 1.5px solid #3b4e5a;
    /deep/ .el-input__inner {
        background-color: #212e38;
        color: #fff;
        border: none;
        border-radius: 0;
        width: 95px;
    }
    /deep/ .el-input__suffix {
        right: 10px;
        .el-icon-arrow-up::before {
        content: "\e78f";
        }
    }
    }
    .array {
    display: inline-block;
    vertical-align: top;
    width: 42px;
    height: 42px;
    line-height: 42px;
    }
 }
 .el-select-dropdown {
	.el-scrollbar {
		.el-scrollbar__wrap {
			.el-select-dropdown__list {
				color: #fff;
				background-color: #355669;
				padding: 0;
				.el-select-dropdown__item {
				color: #fff;
				&.hover {
					color: #45c2fd;
					background-color: #355669;
				}
				&:hover {
					color: #45c2fd;
					background-color: #355669;
				}
				}
			}
		}
	}
}

@media screen and (max-width: 1280px) 
{
.header-bar{
    color: #fff;
    background-color: #151c21;
    display: flex;
    justify-content: flex-end;
    width:85%;
    margin: 0 auto;    
}    
}
@media screen and (max-width: 1024px) 
{
.header-bar{
    color: #fff;
    background-color: #151c21;
    display: flex;
    justify-content: flex-end;
    width:100%;
    margin: 0 auto;    
}    
}
</style>
