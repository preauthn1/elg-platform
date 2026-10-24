import Vue from "vue";
import Router from "vue-router";
import Home from "@/components/v_world_PC/index";
import Main from "@/components/v_world_PC/main";
import C711_PC from "./c711_PC.js";
import C711_Mobile from "./C711_Mobile.js";
import G733_PC from "./G733_PC.js";
import G733_Mobile from "./G733_Mobile.js";
// 版型
import Global from "@/Global";
import secret from "@/utils/secret";
import md5 from "js-md5";
import axios from "axios";
import {
    POINT_CONVERSION_COMPRESSED
} from "constants";

Vue.use(Router);
// Vue.use(axios);
Vue.prototype.$http = axios;
const router = new Router({
    mode: "history",
    routes: [{
        path: "/index",
        component: Home,
        children: [{
            path: "/",
            component: Main
        }]
    }]
});

var base = [{
    path: "/aaa",
    component: resolve => require(["@/components/base/Login"], resolve)
}];

var vWorld = [
    // {
    //     path: '/device',
    //     component: resolve => require(['@/components/v_world/device'], resolve),
    // },
    {
        path: "/device/:deviceId",
        component: resolve => require(["@/components/v_world/device"], resolve)
    },
    {
        path: "/Login",

        component: resolve => require(["@/components/v_world/Login"], resolve)
    },

    {
        path: "/",
        component: resolve => require(["@/components/v_world/Home"], resolve),
        meta: {
            requireAuth: true
        },
        children: [{
                path: "/",
                meta: {
                    footer: true,
                    lobbyBtnLight: true,
                    talkBtn: true,
                    walletBtn: true,
                    settingBtn: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Lobby"], resolve)
            },
            {
                // 忘記密碼
                path: "/ForgetPassword",
                meta: {
                    title: "forget",
                    back: true,
                    setNewpass: true
                },
                component: resolve =>
                    require(["@/components/v_world/Forgetpw"], resolve)
            },

            {
                // 找回密碼
                path: "/RetrievePassword",
                meta: {
                    title: "retrievePassword",
                    back: true,
                    setNewpass: true
                },
                component: resolve =>
                    require(["@/components/v_world/Forgetpw"], resolve)
            },
            {
                // 手機驗證
                path: "/PhoneVerification",
                meta: {
                    title: "phoneVerification",
                    back: true,
                    verifyFinish: true
                },
                component: resolve =>
                    require(["@/components/v_world/Forgetpw"], resolve)
            },
            {
                // 電話地區
                path: "/LocationSelect",
                meta: {
                    title: "countryCode",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/LocationSelect"], resolve)
            },
            {
                path: "/Signup",
                meta: {
                    title: "setAccPw",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/Signup"], resolve)
            },
            {
                path: "/Signup/:shareCode",
                meta: {
                    title: "setAccPw",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/Signup"], resolve)
            },
            {
                // 重設密碼
                path: "/SetPassword",
                meta: {
                    title: "newPassword",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/Setpw"], resolve)
            },

            {
                // 活動列表
                path: "/Events",
                meta: {
                    title: "events",
                    home: true,
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/Events"], resolve)
            },
            {
                path: "/Events_i",
                meta: {
                    title: "events",
                    home: true
                },
                component: resolve =>
                    require(["@/components/v_world/Events"], resolve)
            },
            {
                path: "/EventsContent",
                meta: {
                    title: "eventsContent",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/EventsContent"], resolve)
            },
            {
                // 活動說明
                path: "/EventDescription",
                meta: {
                    title: "eventDescription",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/EventDescription"], resolve)
            },
            {
                // 活動說明
                path: "/EventDescription/:id",
                meta: {
                    title: "eventDescription",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/EventDescription"], resolve)
            },
            {
                // 刮刮卡
                path: "/ScratchCard",
                meta: {
                    title: "scratchCard",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/ScratchCard"], resolve)
            },
            {
                // 輪盤
                path: "/Lottery",
                meta: {
                    title: "lottery",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/Lottery"], resolve)
            },

            {
                // 刮刮卡兌換紀錄
                path: "/ScratchCardRecord",
                meta: {
                    title: "scratchCardTransferRecord",
                    back: true,
                    searchTime: true,
                    scratchCardRecord: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                path: "/GameList",
                meta: {
                    title: "gameList",
                    back: true,
                    home: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/GameList"], resolve)
            },
            {
                path: "/GameList/:vendor",
                meta: {
                    title: "gameList",
                    back: true,
                    home: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/GameList"], resolve)
            },
            {
                path: "/Wallet",
                meta: {
                    title: "wallet",
                    footer: true,
                    lobbyBtn: true,
                    talkBtn: true,
                    walletBtnLight: true,
                    settingBtn: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Wallet"], resolve)
            },
            {
                path: "/Talk",
                meta: {
                    title: "",
                    footer: true,
                    lobbyBtn: true,
                    walletBtn: true,
                    talkBtnLight: true,
                    settingBtn: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Talk"], resolve)
            },
            {
                // 遊戲轉點
                path: "/Points",
                meta: {
                    title: "platformTransfer",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Points"], resolve)
            },
            {
                path: "/Game",
                meta: {
                    title: "game",
                    home: true,
                    back: true,
                    event: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/v_world/Game"], resolve)
            },
            {
                path: "/Game/:id",
                meta: {
                    title: "game",
                    home: true,
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Game"], resolve)
            },
            // {
            //     path: '/Talk',
            //     meta: {title: '', footer: true},
            //     component: resolve =>
            //         require(['@/components/v_world/Talk'], resolve),
            // },
            {
                path: "/GameApp",
                meta: {
                    title: "game",
                    home: true,
                    back: true,
                    event: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/v_world/GameApp"], resolve)
            },
            {
                path: "/GameApp/:vendor",
                meta: {
                    title: "game",
                    home: true,
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/GameApp"], resolve)
            },
            // {    // 停用 合併至points
            //     path: '/Transferin',
            //     meta: { title: 'pointTransferIn'},
            //     component: resolve =>
            //         require(['@/components/v_world/TransferIn'], resolve),
            // },
            // {    // 停用 合併至points
            //     path: '/Transferout',
            //     meta: { title: 'pointTransferOut'},
            //     component: resolve =>
            //         require(['@/components/v_world/TransferOut'], resolve),
            // },
            {
                path: "/Record",
                meta: {
                    title: "recordInquiry",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Record"], resolve)
            },
            {
                /// 紅包紀錄選單頁
                path: "/RedEnvelopeRecord",
                meta: {
                    title: "redEnvelopeRecord",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RedEnvelopeRecord"], resolve)
            },
            {
                path: "/RecordList",
                meta: {
                    title: "transferRecord",
                    back: true,
                    searchTime: true,
                    recordTable: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                // 收到紅包
                path: "/ReceivedRedEnvelope",
                meta: {
                    title: "receivedRedEnvelope",
                    back: true,
                    searchTime: true,
                    redEnvelopeTable: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                // 送出紅包
                path: "/RedEnvelope",
                meta: {
                    title: "redEnvelope",
                    back: true,
                    searchTime: true,
                    redEnvelopeTable: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                // 紅包退還
                path: "/RedEnvelopeRefund",
                meta: {
                    title: "redEnvelopeRefund",
                    back: true,
                    searchTime: true,
                    redEnvelopeTable: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },

            {
                path: "/SuccessfulRecord",
                meta: {
                    title: "successfulRecord",
                    back: true,
                    searchTime: true,
                    customerServiceTransferRecord: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                path: "/ProcessingRecord",
                meta: {
                    title: "processingRecord",
                    back: true,
                    searchTime: true,
                    customerServiceTransferRecord: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                path: "/FailureRecord",
                meta: {
                    title: "failureRecord",
                    back: true,
                    searchTime: true,
                    customerServiceTransferRecord: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                path: "/DateSelection",
                meta: {
                    title: "dateSelection",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/DateSelection"], resolve)
            },
            {
                path: "/StoredValue",
                meta: {
                    title: "storedValuPoints",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/StoredValue"], resolve)
            },
            {
                path: "/CustomerServiceRecord",
                meta: {
                    title: "customerServiceTransferRecord",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require([
                        "@/components/v_world/CustomerServiceRecord"
                    ], resolve)
            },
            {
                //// 客服轉點成功
                path: "/CustomerServiceRecord/Success",
                meta: {
                    title: "successfulRecord",
                    back: true,
                    searchTime: false,
                    customerServiceTransferRecord: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/RecordList"], resolve)
            },
            {
                path: "/Setting",
                meta: {
                    title: "setting",
                    footer: true,
                    lobbyBtn: true,
                    talkBtn: true,
                    walletBtn: true,
                    settingBtnLight: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Setting"], resolve)
            },
            {
                path: "/SettingPersonal",
                meta: {
                    title: "settingPersonal",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/SettingPersonal"], resolve)
            },
            {
                path: "/ChangeImg",
                meta: {
                    title: "changeImg",
                    back: true,
                    extended: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/ChangeImg"], resolve)
            },
            {
                path: "/SettingName",
                meta: {
                    title: "setNickname",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/SettingName"], resolve)
            },
            {
                path: "/ChangePassword",
                meta: {
                    title: "changePassword",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/ChangePassword"], resolve)
            },
            {
                path: "/Language",
                meta: {
                    title: "language",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/Language"], resolve)
            },
            // {
            //     path: '/NewEvent',
            //     meta: {title: 'newEvents', back: true, event: true},
            //     component: resolve =>
            //         require(['@/components/v_world/NewEvent'], resolve),
            // },
            {
                path: "/About",
                meta: {
                    title: "about",
                    back: true,
                    event: true
                },
                component: resolve =>
                    require(["@/components/v_world/About"], resolve)
            },

            {
                path: "/example",
                meta: {
                    title: "game",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/example"], resolve)
            },
            {
                path: "/ButtonClick",
                meta: {
                    title: "game",
                    back: true
                },
                component: resolve =>
                    require(["@/components/v_world/ButtonClick"], resolve)
            }
        ]
    }
];

// 抓template版型
// console.log("T starts");
let domainUrl = location.hostname;
let md5_key = Global.$API_MD5_KEY;
let lang = localStorage.getItem("LOCALE");
let token = [REDACTED]"token");
let jsonMsg = JSON.stringify({
    domain: domainUrl
});
let params = secret.Encrypt(jsonMsg);
let key = md5(params + md5_key);
// console.log("T1");
axios
    .post(
        Global.$API_URL + "api/template",

        {
            params: params,
            key: key
        }, {
            headers: {
                "Accept-Language": lang,
                Authorization: "Bearer " + token
            }
        }
    )

    .then(res => {
        // console.log("T2");
        if (res.data.code == 1000) {
            // console.log("T3 ==1000");
            // console.log("temp main.js ", res.data.data.name);
            // 紀錄template router使用
            sessionStorage.setItem("Template", res.data.data.name);
            sessionStorage.setItem("TemplateTitle", res.data.data.title);
            sessionStorage.setItem("TemplateLogo", res.data.data.logo_mobile);
            sessionStorage.setItem("TemplatePic", res.data.data.icon);
            sessionStorage.setItem("domainUrl", domainUrl);

            // 開關
            sessionStorage.setItem(
                "frontend_register",
                res.data.data.frontend_register
            );

            sessionStorage.setItem("has_agent_layout", res.data.data.has_agent_layout);
            sessionStorage.setItem("has_chat", res.data.data.has_chat);
            sessionStorage.setItem("has_pc", res.data.data.has_pc);
            sessionStorage.setItem("has_mobile", res.data.data.has_mobile);
            sessionStorage.setItem(
                "transaction_all",
                res.data.data.transaction_all
            );

            sessionStorage.setItem(
                "has_agent_layout",
                res.data.data.has_agent_layout
            );

            sessionStorage.setItem(
                "has_online_payment",
                res.data.data.has_online_payment
            );
            sessionStorage.setItem(
                "transaction_all",
                res.data.data.transaction_all
            );
            sessionStorage.setItem("has_app", res.data.data.has_app);
            sessionStorage.setItem("mainSession", "SessionOK");
            // 換TITLE圖片
            // console.log("T4");
            function change_title() {
                document.title = sessionStorage.getItem("TemplateTitle");
                var iconPic = sessionStorage.getItem("TemplatePic");

                $(".iconPic").attr("href", iconPic);
            }
            change_title();
            // console.log("T5");
            // router.push('/login')
        } else {
            alert(res.data.message);
            // console.log("T6 !=1000");
        }
    })
    .catch(error => {
        // console.log("T7 catch");
        console.log(error);

        //! 第一次打不到時 再打一次 (只能再打一次)
        if (sessionStorage.getItem("templateData") != 1) {
            // console.log("T8 reload");

            sessionStorage.setItem("templateData", 1);
            window.location.reload();
        }
    });
var _template = sessionStorage.getItem("Template");

// API
setTimeout(() => {
    // console.log("T start router");
    var _template = sessionStorage.getItem("Template");
    // console.log("T9", _template);
    // console.log("template index.js", _template);
    if (!_template) {
        // console.log("T10.1 !template");
        // console.log("未設定 template");
        router.addRoutes(C711_Mobile);
        // router.addRoutes(vWorld);
    } else {
        // console.log("T10.2 template");
        // console.log("設定 template");
        if (_template == "C711") {
            // console.log("T10.3");
            //    判斷行動端/PC
            var sUserAgent = navigator.userAgent.toLowerCase();
            var bIsIpad = sUserAgent.match(/ipad/i) == "ipad";
            var bIsIphoneOs = sUserAgent.match(/iphone os/i) == "iphone os";
            var bIsMidp = sUserAgent.match(/midp/i) == "midp";
            var bIsUc7 = sUserAgent.match(/rv:0.0.0.0/i) == "rv:0.0.0.0";
            var bIsUc = sUserAgent.match(/ucweb/i) == "ucweb";
            var bIsAndroid = sUserAgent.match(/android/i) == "android";
            var bIsCE = sUserAgent.match(/windows ce/i) == "windows ce";
            var bIsWM = sUserAgent.match(/windows mobile/i) == "windows mobile";

            if (
                bIsIpad ||
                bIsIphoneOs ||
                bIsMidp ||
                bIsUc7 ||
                bIsUc ||
                bIsAndroid ||
                bIsCE ||
                bIsWM
            ) {
                sessionStorage.setItem("PC_MOBILE", "MOBILE");
                // console.log("C711Mobile");
                router.addRoutes(C711_Mobile);
                // console.log("T10.4");
            } else {
                sessionStorage.setItem("PC_MOBILE", "PC");
                // console.log("C711PC");
                router.addRoutes(C711_PC);
                // console.log("T10.5");
                // console.log(C711_PC, "router");
            }
        } else if (_template == "G733") {
            var sUserAgent = navigator.userAgent.toLowerCase();
            var bIsIpad = sUserAgent.match(/ipad/i) == "ipad";
            var bIsIphoneOs = sUserAgent.match(/iphone os/i) == "iphone os";
            var bIsMidp = sUserAgent.match(/midp/i) == "midp";
            var bIsUc7 = sUserAgent.match(/rv:0.0.0.0/i) == "rv:0.0.0.0";
            var bIsUc = sUserAgent.match(/ucweb/i) == "ucweb";
            var bIsAndroid = sUserAgent.match(/android/i) == "android";
            var bIsCE = sUserAgent.match(/windows ce/i) == "windows ce";
            var bIsWM = sUserAgent.match(/windows mobile/i) == "windows mobile";

            if (
                bIsIpad ||
                bIsIphoneOs ||
                bIsMidp ||
                bIsUc7 ||
                bIsUc ||
                bIsAndroid ||
                bIsCE ||
                bIsWM
            ) {
                sessionStorage.setItem("PC_MOBILE", "MOBILE");

                router.addRoutes(G733_Mobile);
            } else {
                sessionStorage.setItem("PC_MOBILE", "PC");

                router.addRoutes(G733_PC);
            }
        } else {
            router.addRoutes(C711_Mobile);
        }
    }
}, 1500);

// validate is Login
router.beforeEach((to, from, next) => {
    // 驗證是否有登入
    var isLogin = sessionStorage.getItem("token");
    if (!isLogin) {
        // 排除不需登入的頁面
        if (
            to.path !== "/login" &&
            to.path !== "/Login" &&
            to.path !== "/Signup" &&
            to.path !== "/SignupTEST" &&
            to.path !== "/Signup#re" &&
            to.path.substring(0, 8) !== "/Signup/" &&
            to.path.substring(0, 8) !== "/device/" &&
            to.path !== "/CustomerService" &&
            to.path !== "/ForgetPassword" &&
            to.path !== "/LocationSelect" &&
            to.path !== "/RetrievePassword" &&
            to.path !== "/PhoneVerification" &&
            to.path !== "/SetPassword" &&
            to.path !== "/example" &&
            to.path !== "/stepone" &&
            to.path !== "/QRcodeOpen" &&
            to.path.substring(0, 12) !== "/QRcodeOpen/" &&
            /// c711_pc
            to.path !== "/Lobby"
            // to.path !== "/QA" &&
            // to.path !== "/Rule" &&
            // to.path !== "/Privacy" &&
            // to.path !== "/Cookies"
        ) {
            if (sessionStorage.getItem("PC_MOBILE") == "PC") {
                isLogin ? next() : next("/Lobby");
            } else {
                isLogin ? next() : next("/login");
            }
        } else {
            next();
        }
    } else {
        next();
    }
});

export default router;
