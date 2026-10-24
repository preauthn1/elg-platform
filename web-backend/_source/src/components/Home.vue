<template>
<div class="app" :class="{'is-collapsed': collapsed }">
    <!-- 左側menu -->
    <div class="side-nav">
        <div class="side-nav-inner">
            <div class="side-nav-logo">
                <a href="/index">
                    &nbsp;
                    <!--<div class="logo logo-dark" style="background-image: url('assets/images/logo/logo.png')"></div>
                    <div class="logo logo-white" style="background-image: url('assets/images/logo/logo-white.png')"></div>-->
                </a>
                <div class="mobile-toggle side-nav-toggle">
                    <a href="">
                        <i class="ti-arrow-circle-left"></i>
                    </a>
                </div>
            </div>
            <ul class="side-nav-menu scrollable">
                <li class="nav-item active">
                    <a class="mrg-top-30" href="/index" @click="goIndex()">
                        <span class="icon-holder">
                            <i class="ti-home"></i>
                        </span>
                        <span class="title">{{$t('dashboard')}}</span>
                    </a>
                </li>
                <li v-for="(item,i) in getUserMenu" :key="i" v-if="item['submenu']" class="nav-item dropdown" :class="{open: chosen == item.id}" :id="item.id">
                    <a class="dropdown-toggle" href="javascript:void(0);" @click="toggleItem(item.id)">
                        <span class="icon-holder">
                            <i :class="item.symbol"></i>
                        </span>
                        <!-- <span class="title">{{item.name}}</span> -->
                        <span class="title">{{$t('menu.'+ item.code)}}</span>
                        <span class="arrow">
                            <i class="ti-angle-right"></i>
                        </span>
                    </a>
                    <ul v-for="(subitem ,i) in item.submenu" :key="i" class="dropdown-menu">                                
                        <li :class="{active : '/'+subitem.url == $route.path}" @click="setMenuId(subitem)">
                            <!-- <router-link :to="'/'+subitem.url">{{subitem.name}}</router-link> -->
                            <router-link :to="'/'+subitem.url">{{$t('menu.'+ subitem.code)}}</router-link>
                        </li>
                    </ul>
                </li>
                <li v-else class="nav-item">
                    <a class="mrg-top-30" :href="item.url">
                        <span class="icon-holder">
                            <i :class="item.symbol"></i>
                        </span>
                        <!-- <span class="title">{{item.name}}</span> -->
                        <span class="title">{{$t('menu.'+ item.code)}}</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <!-- 右側區塊 -->
    <div class="page-container">
        <!-- header功能選單 -->
        <div class="header navbar">
            <div class="header-container">
                <ul class="nav-left">
                    <li>
                        <a class="side-nav-toggle" href="javascript:void(0);" @click="toggleMenu()">
                            <i class="ti-view-grid"></i>
                        </a>
                    </li>
                </ul>
                <ul class="nav-right">
                    <li class="user-profile dropdown" :class="{show : showSubnav}">
                        <a  class="dropdown-toggle" data-toggle="dropdown" @click="toggleSubnav()">
                            <img class="profile-img img-fluid" src="../assets/images/user.jpg" alt="">
                            <div class="user-info">
                                <span class="name pdd-right-5">{{username}}</span>
                                <i class="ti-angle-down font-size-10"></i>
                            </div>
                        </a>
                        <ul class="dropdown-menu">
                            <!-- <li>
                                <a href="">
                                    <i class="ti-settings pdd-right-10"></i>
                                    <span>{{$t('setting')}}</span>
                                </a>
                            </li>
                            <li role="separator" class="divider"></li> -->
                            <li>
                                <a @click="logOut()" id="logout">
                                    <i class="ti-power-off pdd-right-10"></i>
                                    <span>{{$t('logout')}}</span>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li class="notifications dropdown" :class="{show : showLang}">
                        <a class="dropdown-toggle" data-toggle="dropdown" @click="toggleShowLang()">
                            <i class="ei-show"></i>
                        </a>
                        <ul class="dropdown-menu ">
                            <li class="notice-header">
                                <i class="ti-bell pdd-right-10"></i>
                                <span>{{$t('languages')}}</span>
                            </li>
                            <li>
                                <ul class="list-info overflow-y-auto relative scrollable">
                                    <li v-for="(item,i) in getLanguages" :key="i" @click="changeLang(item)">
                                        <a :id="i" class="lang">
                                            <span class="thumb-img bg-danger" >
                                                <span class="text-white">{{langIcon(item.code)}}</span>
                                            </span>
                                            <div class="info">
                                                <span class="title">
                                                    <span class="font-size-14 text-semibold">{{item.name}}</span>
                                                    <!--<span class="text-gray">likes your <span class="text-dark">photo</span></span>-->
                                                </span>
                                                <span class="sub-title">{{item.name}}</span>
                                            </div>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
        <!-- main主要區塊router-view -->
        <router-view/>
    </div>
</div>
</template>
<script>
// plugins css
import '../assets/vendors/bootstrap/dist/css/bootstrap.css'
import '../assets/vendors/PACE/themes/blue/pace-theme-minimal.css'
import '../assets/vendors/perfect-scrollbar/css/perfect-scrollbar.min.css'
// page plugins css
import '../assets/vendors/bower-jvectormap/jquery-jvectormap-1.2.2.css'
import '../assets/vendors/nvd3/build/nv.d3.min.css'
import '../assets/vendors/selectize/dist/css/selectize.default.css'
import '../assets/vendors/summernote/dist/summernote.css'
import '../assets/vendors/nvd3/build/nv.d3.min.css'
import '../assets/vendors/datatables/media/css/jquery.dataTables.css'
// core css
import '../assets/css/ei-icon.css'
import '../assets/css/themify-icons.css'
import '../assets/css/font-awesome.min.css'
import '../assets/css/animate.min.css'
import '../assets/css/app.css'

import { mapGetters } from 'vuex'
export default {
    computed: {
        ...mapGetters(['getUserMenu','getLanguages'])
    },
    data() {
        return {
            chosen: null,
            username: sessionStorage.getItem('username'),
            collapsed: false,
            showSubnav: false,
            showLang: false
        }
    },
    methods: {
        goIndex() {
            sessionStorage.setItem('chosen_menu',0)
        },
        toggleItem(id) {
            if(this.chosen == id){
                this.chosen = null
            } else {
                this.chosen = id
            }
        },
        setMenuId(item) {
            // this.$router.push('/'+ url)
            sessionStorage.setItem('chosen_menu',item.parent)
        },
        // 主選單縮放
        toggleMenu() {
            this.collapsed = !this.collapsed
        },
        // 設定登出
        toggleSubnav() {
            this.showSubnav = !this.showSubnav
            this.showLang = false
        },
        // 登出
        logOut(){
            this.$store.dispatch('action_Logout')
        },
        // 語言
        toggleShowLang() {
            this.showLang = !this.showLang
            this.showSubnav = false
        },
        langIcon(code){
            let icon = code.substr(0,1)
            return icon
        },
        changeLang(lang){
            localStorage.setItem('LOCALE', lang.code)
            this.$i18n.locale = lang.code
            // this.$store.dispatch('action_userMenu_list', sessionStorage.getItem('user_id'))
            window.location.reload()
        }
    },
    created() {
        this.$store.dispatch('action_userMenu_list', sessionStorage.getItem('user_id'))
        this.$store.dispatch('action_languages')

        this.chosen = sessionStorage.getItem('chosen_menu') //重整紀錄選取的menu item 

        // 登入只取一次國家列
        if(!sessionStorage.getItem('country_list')){
            this.$store.dispatch('action_country')
        }
    }
}
</script>