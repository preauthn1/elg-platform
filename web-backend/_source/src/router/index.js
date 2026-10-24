import Vue from 'vue'
import Router from 'vue-router'
// import HelloWorld from '@/components/HelloWorld'
import Login from '@/components/Login'
import Home from '@/components/Home'
// import Backend_router from "./Backend_router.js";
// import { homedir } from 'os';

Vue.use(Router)

let router = new Router({
    mode: "history",
    routes: [
        {
            path: "/Login",
            name: 'Login',
            component: Login,
            // children: [
            //     {
            //         path: "/",
            //         component: resolve =>require(["@/components/Login"], resolve)
            //     },
            //     {
            //         path: "index",
            //         component: resolve =>require(["@/components/Login"], resolve)
            //     }
            // ]
        },
        {
            path: "/",
            component: resolve =>require(["@/components/Home"], resolve),
            children: [
                {
                    path: "/index",
                   component: resolve =>require(["@/components/Index"], resolve)
                },
                {
                    path: "/user/index",
                   component: resolve =>require(["@/components/user/index"], resolve)
                },
                {
                    path: "/user/edit",
                   component: resolve =>require(["@/components/user/edit"], resolve)
                },
                {
                    path: "/agent/index",
                   component: resolve =>require(["@/components/agent/index"], resolve)
                },
                {
                    path: "/agent/edit",
                   component: resolve =>require(["@/components/agent/edit"], resolve)
                },
                {
                    path: "/agent/profile",
                   component: resolve =>require(["@/components/agent/profile"], resolve)
                },
                // 廣告板位
                {
                    path: "/advertisementPath/index",
                   component: resolve =>require(["@/components/advertisementPath/index"], resolve)
                },
                {
                    path: "/advertisementPath/edit",
                   component: resolve =>require(["@/components/advertisementPath/edit"], resolve)
                },
                // 廣告
                {
                    path: "/advertisement/index",
                   component: resolve =>require(["@/components/advertisement/index"], resolve)
                },
                {
                    path: "/advertisement/edit",
                   component: resolve =>require(["@/components/advertisement/edit"], resolve)
                },
                // 國家
                {
                    path: "/country/index",
                   component: resolve =>require(["@/components/country/index"], resolve)
                },
                // 貨幣
                {
                    path: "/currency/index",
                   component: resolve =>require(["@/components/currency/index"], resolve)
                },
                // 模板
                {
                    path: "/template/index",
                   component: resolve =>require(["@/components/template/index"], resolve)
                },
                // 遊戲類型列表
                {
                    path: "/game_type/index",
                   component: resolve =>require(["@/components/gameType/index"], resolve)
                },
                // 廠商管理
                {
                    path: "/vendor/index",
                   component: resolve =>require(["@/components/vendor/index"], resolve)
                },
                {
                    path: "/vendor/edit",
                   component: resolve =>require(["@/components/vendor/edit"], resolve)
                },
                // 遊戲管理
                {
                    path: "/game/index",
                   component: resolve =>require(["@/components/game/index"], resolve)
                },
                {
                    path: "/game/edit",
                   component: resolve =>require(["@/components/game/edit"], resolve)
                },
                // 活動管理
                {
                    path: "/event/index",
                   component: resolve =>require(["@/components/event/index"], resolve)
                },
                {
                    path: "/event/edit",
                   component: resolve =>require(["@/components/event/edit"], resolve)
                },
            ]
        }
    ]
})
// router.addRoutes(Backend_router)

router.beforeEach((to, from, next) => {
    // 驗證是否有登入
    var isLogin = sessionStorage.getItem("token");
    if (!isLogin) {
        // 排除不需登入的頁面
        if (
            to.path !== "/Login"
        ) {
            isLogin ? next() : next("/Login");
        } else {
            next();
        }
    } else {
        next();
    }
});

export default router