export var C711_Mobile = [{
        path: "/device/:deviceId",
        component: resolve =>
            require(["@/components/C711_Mobile/device"], resolve)
    },

    {
        path: "/Login",
        component: resolve =>
            require(["@/components/C711_Mobile/Login"], resolve)
    },
    {
        path: "/",
        component: resolve =>
            require(["@/components/C711_Mobile/Home"], resolve),
        mata: {
            requireAuth: true
        },
        children: [{
                path: "/",
                meta: {
                    isSettingBtn: true,
                    isSearchIcon: true,
                    footer: true,
                    lobbyBtnLight: true,
                    isShowLogo: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Lobby"], resolve)
            },
            {
                path: "/Lobby",
                meta: {
                    footer: true,
                    lobbyBtnLight: true,
                    isSettingBtn: true,
                    isSearchIcon: true,
                    isShowLogo: true,
                },
                redirect: {
                    // 跳轉Lobby
                    path: "/"
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Lobby"], resolve)
            },
            {
                //    客服轉跳
                path: "/CustomerService",
                meta: {
                    title: "forget",
                    back: true,
                    setNewpass: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/CustomerService"
                    ], resolve)
            },
            {
                //    bannerURL
                path: "/Information",
                meta: {
                    title: "epidemicIntelligence",
                    back: true,
                    setNewpass: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/BannerURL"], resolve)
            },
            // {
            //     // 忘記密碼
            //     path: "/stepone",
            //     meta: { title: "forget", back: true, setNewpass: true },
            //     component: resolve =>
            //         require(["@/components/C711_Mobile/StepOne"], resolve)
            // },
            {
                // 忘記密碼
                path: "/ForgetPassword",
                meta: {
                    title: "forget",
                    back: true,
                    setNewpass: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Forgetpw"], resolve)
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
                    require(["@/components/C711_Mobile/Forgetpw"], resolve)
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
                    require(["@/components/C711_Mobile/Forgetpw"], resolve)
            },
            // {
            //     // 電話地區
            //     path: '/LocationSelect',
            //     meta: {title: 'countryCode', back: true, event: true},
            //     component: resolve =>
            //         require(['@/components/v_world/LocationSelect'], resolve),
            // },
            {
                path: "/Signup/:shareCode",
                meta: {
                    title: "signUp",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Signup"], resolve)
            },
            {
                path: "/Signup",
                meta: {
                    title: "signUp",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Signup"], resolve)
            },

            {
                // 重設密碼
                path: "/SetPassword",
                meta: {
                    title: "newPassword",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Setpw"], resolve)
            },

            {
                // 活動列表
                path: "/Events",
                meta: {
                    title: "events",
                    home: true,
                    footer: true,
                    eventBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Events"], resolve)
            },
            {
                path: "/Events_i",
                meta: {
                    title: "events",
                    home: true,
                    footer: true,
                    eventBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Events"], resolve)
            },
            {
                // 登入活動模式說明

                path: "/EventMode",
                meta: {
                    title: "eventMode",
                    home: true,
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/EventMode"], resolve)
            },
            // {
            //     path: '/EventsContent',
            //     meta: {title: 'eventsContent', back: true},
            //     component: resolve =>
            //         require(['@/components/v_world/EventsContent'], resolve),
            // },
            {
                // 活動說明
                path: "/EventDescription/:id",
                meta: {
                    title: "eventDescription",
                    back: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/EventDescription"
                    ], resolve)
            },
            {
                // 活動說明
                path: "/EventDescription",
                meta: {
                    title: "eventDescription",
                    back: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/EventDescription"
                    ], resolve)
            },

            {
                // 活動說明
                path: "/EventTicket/:id",
                meta: {
                    title: "myLotteryTicket",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/EventTicket"], resolve)
            },
            {
                // 摸彩票券
                path: "/EventTicket",
                meta: {
                    title: "myLotteryTicket",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/EventTicket"], resolve)
            },
            {
                // 刮刮卡
                path: "/ScratchCard",
                meta: {
                    title: "scratchCard",
                    back: true
                },
                component: resolve =>
                    require(["@/components/ScratchCard"], resolve)
            },
            {
                // 輪盤
                path: "/Lottery",
                meta: {
                    title: "lottery",
                    back: true
                },
                component: resolve => require(["@/components/Lottery"], resolve)
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
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                path: "/GameList/:id/:vendorID/:eventOpenGame",
                meta: {
                    title: "",
                    back: true,
                    getBalance: true,
                    isShowTileText: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/GameList"], resolve)
            },
            {
                path: "/GameList/:vendorID",
                meta: {
                    title: "",
                    name: "GameList",
                    isShowTileText: true,
                    back: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/GameList"], resolve)
            },
            {
                path: "/GameList",
                meta: {
                    title: "",
                    name: "GameList",
                    back: true,
                    isShowTileText: true,
                    getBalance: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/GameList"], resolve)
            },
            {
                path: "/GameListSearch",
                meta: {
                    title: "search",
                    name: "search",
                    isShowTileText: true,
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/GameListSearch"], resolve)
            },
            {
                path: "/Wallet",
                meta: {
                    title: "wallet",
                    footer: true,
                    walletBtnLight: true,

                },
                component: resolve =>
                    require(["@/components/v_world/Wallet"], resolve)
            },
            {
                path: "/Talk",
                meta: {
                    title: "",
                    footer: true,
                    talkBtnLight: true,

                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Talk"], resolve)
            },
            {
                // 遊戲轉點
                path: "/Points",
                meta: {
                    title: "platformTransfer",
                    back: true,
                    record: true,
                    getPoints: true,
                    footer: true,
                    walletBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Points"], resolve)
            },
            {
                path: "/Game/:id/:vendorID",
                meta: {
                    title: "game",
                    back: true,

                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Game"], resolve)
            },
            {
                path: "/Game/:id",
                meta: {
                    title: "game",
                    back: true,

                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Game"], resolve)
            },

            {
                path: "/Game",
                meta: {
                    title: "game",
                    back: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Game"], resolve)
            },
            {
                path: "/GameApp/:vendor",
                meta: {
                    title: "game",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/GameApp"], resolve)
            },
            {
                path: "/GameApp",
                meta: {
                    title: "game",
                    back: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/GameApp"], resolve)
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
                    title: "wallet",
                    back: true,
                    footer: true,
                    isShowTileText: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Record"], resolve)
            },
            {
                path: "/PointAndRecord",
                meta: {
                    title: "wallet",
                    back: true,
                    footer: true,
                    isShowTileText: true,
                    walletBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Record"], resolve)
            },
            {
                /// 紅包紀錄選單頁
                path: "/RedEnvelopeRecord",
                meta: {
                    title: "redEnvelopeRecord",
                    back: true,
                    settingPoints: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/RedEnvelopeRecord"
                    ], resolve)
            },
            {
                path: "/RecordList",
                meta: {
                    title: "transferRecord",
                    back: true,
                    searchTime: true,
                    recordTable: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                // 收到紅包
                path: "/ReceivedRedEnvelope",
                meta: {
                    title: "receivedRedEnvelope",
                    back: true,
                    searchTime: true,
                    redEnvelopeTable: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                // 送出紅包
                path: "/RedEnvelope",
                meta: {
                    title: "redEnvelope",
                    back: true,
                    searchTime: true,
                    redEnvelopeTable: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                // 紅包退還
                path: "/RedEnvelopeRefund",
                meta: {
                    title: "redEnvelopeRefund",
                    back: true,
                    searchTime: true,
                    redEnvelopeTable: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },

            {
                path: "/SuccessfulRecord",
                meta: {
                    title: "successfulRecord",
                    back: true,
                    searchTime: true,
                    customerServiceTransferRecord: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                path: "/ProcessingRecord",
                meta: {
                    title: "processingRecord",
                    back: true,
                    searchTime: true,
                    customerServiceTransferRecord: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                path: "/FailureRecord",
                meta: {
                    title: "failureRecord",
                    back: true,
                    searchTime: true,
                    customerServiceTransferRecord: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                path: "/DateSelection",
                meta: {
                    title: "dateSelection",
                    back: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/DateSelection"], resolve)
            },
            {
                path: "/StoredValue",
                meta: {
                    title: "storedValuPoints",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/StoredValue"], resolve)
            },
            {
                //// 客服轉點成功
                path: "/CustomerServiceRecord/Success",
                meta: {
                    title: "successfulRecord",
                    back: true,
                    searchTime: false,
                    customerServiceTransferRecord: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            {
                path: "/Setting",
                meta: {
                    back: true,
                    title: "setting",
                    isShowTileText: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/SettingPersonal"], resolve)
            },
            {
                path: "/SettingPersonal",
                meta: {
                    title: "setting",
                    back: true,
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/SettingPersonal"
                    ], resolve)
            },
            {
                path: "/SettingName",
                meta: {
                    title: "setNickname",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/SettingName"], resolve)
            },
            {
                path: "/ChangePassword",
                meta: {
                    title: "changePassword",
                    back: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/ChangePassword"
                    ], resolve)
            },
            {
                path: "/Language",
                meta: {
                    title: "language",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Language"], resolve)
            },
            {
                path: "/About",
                meta: {
                    title: "about",
                    back: true,
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/About"], resolve)
            },
            {
                path: "/ButtonClick/:id",
                meta: {
                    title: "game",
                    back: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/ButtonClick"], resolve)
            },
            {
                path: "/ButtonClick",
                meta: {
                    title: "game",
                    back: true,
                    gamePointBtn: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/ButtonClick"], resolve)
            },
            // app 遊戲商
            {
                path: "/QRcodeOpen/:id/:vendor/:token",
                meta: {
                    title: "game"
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/QRcodeOpen"], resolve)
            },
            {
                path: "/QRcodeOpen",
                meta: {
                    title: "game"
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/QRcodeOpen"], resolve)
            },

            {
                path: "/Agent",
                meta: {
                    title: "Agent_AgentMenu",
                    back: true,
                    footer: true,
                    agentBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Agent"], resolve)
            },

            {
                path: "/RD_Debug",
                meta: {
                    title: "RD_Debug",
                    back: true,
                    footer: true,
                    agentBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RD_Debug"], resolve)
            },

            {
                path: "/AgentRules",
                meta: {
                    title: "Agent_Title_Rule",
                    footer: true,
                    agentBtnLight: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/AgentRules"], resolve)
            },

            {
                path: "/AgentMyPlayers",
                meta: {
                    title: "Agent_Label_MyPromote",
                    back: true,
                    footer: true,
                    agentBtnLight: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/AgentMyPlayers"
                    ], resolve)
            },

            {
                path: "/AgentMyWithdrawal",
                meta: {
                    title: "Agent_Title_ApplyWithdrawl",
                    back: true,
                    footer: true,
                    agentBtnLight: true
                },
                component: resolve =>
                    require([
                        "@/components/C711_Mobile/AgentMyWithdrawal"
                    ], resolve)
            },

            // {
            //     path: '/button',
            //     meta: {title: 'game', back: true},
            //     component: resolve =>
            //         require(['@/components/v_world/button'], resolve),
            // },
            {
                path: "/Modal",
                meta: {
                    title: "game",
                    back: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/Modal"], resolve)
            }
        ]
    }
];
export default C711_Mobile;
