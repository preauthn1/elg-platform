export var G733_PC = [
    {
        path: "/Login",
        component: resolve => require(["@/components/G733/Login"], resolve)
    },
    {
        path: "/",
        component: resolve => require(["@/components/G733/Home"], resolve),
        mata: { requireAuth: true },

        children: [
            {
                path: "/",
                meta: {
                    footer: true,
                    lobbyBtnLight: true,
                    talkBtn: true,
                    walletBtn: true,
                    settingBtn: true,
                    event: true,
                    settingPoints: true,
                    hamburger: true,
                    pc_gamer: true,
                    banner: true,
                    unionBorder: true,
                    pcApp: true,
                    fixedBtn: true,
                    footer_block: true
                },
                redirect: {
                    // 跳轉Lobby
                    path: "/Lobby"
                },
                component: resolve =>
                    require(["@/components/G733/Lobby"], resolve),
                children: [
                    {
                        path: "/Lobby",
                        meta: {
                            footer: true,
                            lobbyBtnLight: true,
                            talkBtn: true,
                            walletBtn: true,
                            settingBtn: true,
                            event: true,
                            settingPoints: true,
                            hamburger: true,
                            pc_gamer: true,
                            banner: true,
                            unionBorder: true,
                            pcApp: true,
                            fixedBtn: true,
                            footer_block: true
                        },
                        component: resolve =>
                            require(["@/components/G733/Lobby_Swiper"], resolve)
                    },
                    // {
                    //     path: "/Lobby/:id/:vendorID/:eventOpenGame",
                    //     meta: { direction_None: true, fixedBtn: true },
                    //     component: resolve =>
                    //         require([
                    //             "@/components/G733/Lobby_list"
                    //         ], resolve)
                    // },
                    {
                        path: "/LobbyList",
                        meta: {
                            footer: true,
                            lobbyBtnLight: true,
                            talkBtn: true,
                            walletBtn: true,
                            settingBtn: true,
                            event: true,
                            settingPoints: true,
                            hamburger: true,
                            pc_gamer: true,
                            banner: true,
                            unionBorder: true,
                            pcApp: true,
                            fixedBtn: true,
                            footer_block: true
                        },
                        component: resolve =>
                            require(["@/components/G733/Lobby_List"], resolve)
                    },
                    {
                        path: "/GameList/:id/:vendorID/:eventOpenGame",
                        meta: {
                            title: "gameList",
                            // name: "gameList",
                            back: true,
                            // home: true,
                            event: true,
                            getBalance: true,
                            gamePointBtn: true,
                            hamburger: true,
                            pc_gamer: true,
                            banner: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/GameList"], resolve)
                    },

                    {
                        path: "/GameList/:vendorID",
                        meta: {
                            title: "gameList",
                            // name: "gameList",
                            back: true,
                            // home: true,
                            event: true,
                            getBalance: true,
                            gamePointBtn: true,
                            hamburger: true,
                            pc_gamer: true,
                            banner: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/GameList"], resolve)
                    },
                    {
                        path: "/GameList",
                        meta: {
                            title: "gameList",
                            name: "GameList",
                            back: true,
                            // homeq: true,
                            // gameListBack: true,
                            event: true,
                            getBalance: true,
                            gamePointBtn: true,
                            hamburger: true,
                            pc_gamer: true,
                            banner: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/GameList"], resolve)
                    },
                    {
                        path: "/GameHotNew/:type/:typeMore",
                        meta: {
                            title: "gameList",
                            name: "GameList",
                            back: true,
                            // homeq: true,
                            // gameListBack: true,
                            event: true,
                            pc_gamer: true,
                            banner: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/GameHotNew"], resolve)
                    },
                    {
                        path: "/GameHotNew",
                        meta: {
                            title: "gameList",
                            name: "GameList",
                            back: true,
                            // homeq: true,
                            // gameListBack: true,
                            event: true,
                            getBalance: true,
                            gamePointBtn: true,
                            pc_gamer: true,
                            banner: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/GameHotNew"], resolve)
                    },
                    {
                        // 遊戲轉點
                        path: "/Points",
                        meta: {
                            title: "platformTransfer",
                            back: true,
                            event: true,
                            record: true,
                            getPoints: true,
                            footer: true,
                            lobbyBtn: true,
                            talkBtn: true,
                            walletBtnLight: true,
                            settingBtn: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/Points"], resolve)
                    },
                    {
                        path: "/Record",
                        meta: {
                            title: "recordInquiry",
                            back: true,
                            event: true,
                            settingPoints: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/Record"], resolve)
                    },
                    {
                        /// 紅包紀錄選單頁
                        path: "/RedEnvelopeRecord",
                        meta: {
                            title: "redEnvelopeRecord",
                            back: true,
                            event: true,
                            settingPoints: true,
                            fixedBtn: true
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
                            event: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/RecordList"], resolve)
                    },
                    {
                        // 刮刮卡兌換紀錄
                        path: "/ScratchCardRecord",
                        meta: {
                            title: "scratchCardTransferRecord",
                            back: true,
                            searchTime: true,
                            scratchCardRecord: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/RecordList"], resolve)
                    },
                    {
                        // 收到紅包
                        path: "/ReceivedRedEnvelope",
                        meta: {
                            title: "receivedRedEnvelope",
                            back: true,
                            searchTime: true,
                            redEnvelopeTable: true,
                            event: true,
                            fixedBtn: true
                        },
                        component: resolve =>
                            require(["@/components/G733/RecordList"], resolve)
                    },
                    // {
                    //     path: "/DateSelection",
                    //     meta: {
                    //         title: "dateSelection",
                    //         back: true,
                    //         event: true
                    //     },
                    //     component: resolve =>
                    //         require([
                    //             "@/components/G733/DateSelection"
                    //         ], resolve)
                    // },
                    {
                        path: "/Setting",
                        meta: {
                            title: "setting",
                            footer: true,
                            lobbyBtn: true,
                            talkBtn: true,
                            walletBtn: true,
                            settingBtnLight: true,
                            event: true,
                            settingPoints: true
                        },
                        component: resolve =>
                            require(["@/components/G733/Setting"], resolve)
                    },
                    {
                        path: "/SettingPersonal",
                        meta: {
                            title: "settingPersonal",
                            back: true,
                            event: true
                        },
                        component: resolve =>
                            require([
                                "@/components/G733/SettingPersonal"
                            ], resolve)
                    },
                    {
                        path: "/SettingName",
                        meta: { title: "setNickname", back: true, event: true },
                        component: resolve =>
                            require(["@/components/G733/SettingName"], resolve)
                    },
                    {
                        path: "/ChangePassword",
                        meta: {
                            title: "changePassword",
                            back: true,
                            event: true
                        },
                        component: resolve =>
                            require([
                                "@/components/G733/ChangePassword"
                            ], resolve)
                    },
                    {
                        path: "/Language",
                        meta: { title: "language", back: true, event: true },
                        component: resolve =>
                            require(["@/components/G733/Language"], resolve)
                    },
                    {
                        path: "/StoredValue",
                        meta: {
                            title: "storedValuPoints",
                            back: true,
                            event: true
                        },
                        component: resolve =>
                            require(["@/components/G733/StoredValue"], resolve)
                    }
                ]
            },

            {
                // 忘記密碼
                path: "/ForgetPassword",
                meta: { title: "forget", back: true, setNewpass: true },
                component: resolve =>
                    require(["@/components/G733/Forgetpw"], resolve)
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
                    require(["@/components/G733/Forgetpw"], resolve)
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
                    require(["@/components/G733/Forgetpw"], resolve)
            },
            // {
            //     // 電話地區
            //     path: '/LocationSelect',
            //     meta: {title: 'countryCode', back: true, event: true},
            //     component: resolve =>
            //         require(['@/components/v_world/LocationSelect'], resolve),
            // },
            {
                path: "/Events_i",
                meta: { title: "events", home: true },
                component: resolve =>
                    require(["@/components/G733/Events"], resolve)
            },
            {
                // 登入活動模式說明

                path: "/EventMode",
                meta: { title: "eventMode", home: true, back: true },
                component: resolve =>
                    require(["@/components/G733/EventMode"], resolve)
            },
            {
                path: "/Signup",
                meta: { title: "signUp", back: true },
                component: resolve =>
                    require(["@/components/G733/Signup"], resolve)
            },
            // {
            //     path: "/Signup/:shareCode",
            //     meta: { title: "signUp", back: true },
            //     component: resolve =>
            //         require(["@/components/G733/Signup"], resolve)
            // },
            {
                // 重設密碼
                path: "/SetPassword",
                meta: { title: "newPassword", back: true },
                component: resolve =>
                    require(["@/components/G733/Setpw"], resolve)
            },
            {
                path: "/Events_i",
                meta: { title: "events", home: true },
                component: resolve =>
                    require(["@/components/G733/Events"], resolve)
            },
            {
                // 活動列表
                path: "/Events",
                meta: { title: "events", home: true, back: true },
                component: resolve =>
                    require(["@/components/G733/Events"], resolve)
            },

            {
                // 活動說明
                path: "/EventDescription/:id",
                meta: { title: "eventDescription", back: true },
                component: resolve =>
                    require(["@/components/G733/EventDescription"], resolve)
            },
            {
                // 活動說明
                path: "/EventDescription",
                meta: { title: "eventDescription", back: true },
                component: resolve =>
                    require(["@/components/G733/EventDescription"], resolve)
            },
            {
                // 摸彩票券
                path: "/EventTicket/:id",
                meta: { title: "myLotteryTicket", back: true },
                component: resolve =>
                    require(["@/components/G733/EventTicket"], resolve)
            },
            {
                // 摸彩票券
                path: "/EventTicket",
                meta: { title: "myLotteryTicket", back: true },
                component: resolve =>
                    require(["@/components/G733/EventTicket"], resolve)
            },

            {
                // 刮刮卡
                path: "/ScratchCard",
                meta: { title: "scratchCard", back: true },
                component: resolve =>
                    require(["@/components/ScratchCard"], resolve)
            },
            {
                // 輪盤
                path: "/Lottery",
                meta: { title: "lottery", back: true },
                component: resolve => require(["@/components/Lottery"], resolve)
            },

            // {
            //     path: "/Hamburger",
            //     meta: {
            //         title: "gameList",
            //         name: "GameList",
            //         back: true,
            //         // homeq: true,
            //         // gameListBack: true,
            //         event: true,
            //         getBalance: true,
            //         gamePointBtn: true
            //     },
            //     component: resolve =>
            //         require(["@/components/G733/Hamburger"], resolve)
            // },
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
                    require(["@/components/G733/Talk"], resolve)
            },

            {
                path: "/Game/:id",
                meta: { title: "game", home: true, back: true, event: true },
                component: resolve =>
                    require(["@/components/G733/Game"], resolve)
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
                    require(["@/components/G733/Game"], resolve)
            },

            // {
            //     path: "/GameApp/:vendor",
            //     meta: { title: "game", home: true, back: true, event: true },
            //     component: resolve =>
            //         require(["@/components/G733/GameApp"], resolve)
            // },
            // {
            //     path: "/GameApp",
            //     meta: {
            //         title: "game",
            //         home: true,
            //         back: true,
            //         event: true,
            //         gamePointBtn: true
            //     },
            //     component: resolve =>
            //         require(["@/components/G733/GameApp"], resolve)
            // },
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

            // pc?
            // {
            //     // 刮刮卡兌換紀錄
            //     path: "/ScratchCardRecord/:banner",
            //     meta: {
            //         title: "scratchCardTransferRecord",
            //         back: true,
            //         searchTime: true,
            //         scratchCardRecord: true
            //     },
            //     component: resolve =>
            //         require(["@/components/G733/RecordList"], resolve)
            // },

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
                    event: true
                },
                component: resolve =>
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            // 成功紀錄
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
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            // 處理中記錄
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
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },
            // 失敗紀錄
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
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },

            // 成功紀錄
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
                    require(["@/components/C711_Mobile/RecordList"], resolve)
            },

            // {
            //     path: '/ChangeImg',
            //     meta: {
            //         title: 'changeImg',
            //         back: true,
            //         extended: true,
            //         event: true,
            //     },
            //     component: resolve =>
            //         require(['@/components/v_world/ChangeImg'], resolve),
            // },

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
                    event: true,
                    qa: true,
                    rule: true,
                    privacy: true,
                    cookies: true
                },
                component: resolve =>
                    require(["@/components/G733/About"], resolve)
            },
            {
                path: "/QA",
                meta: { title: "QA", back: true, event: true, qa: true },
                component: resolve =>
                    require(["@/components/G733/About"], resolve)
            },
            {
                path: "/Rule",
                meta: { title: "Rule", back: true, event: true, rule: true },
                component: resolve =>
                    require(["@/components/G733/About"], resolve)
            },
            {
                path: "/Privacy",
                meta: {
                    title: "Privacy",
                    back: true,
                    event: true,
                    privacy: true
                },
                component: resolve =>
                    require(["@/components/G733/About"], resolve)
            },
            {
                path: "/Cookies",
                meta: {
                    title: "Cookies",
                    back: true,
                    event: true,
                    cookies: true
                },
                component: resolve =>
                    require(["@/components/G733/About"], resolve)
            },
            {
                path: "/ButtonClick",
                meta: { title: "game", back: true },
                component: resolve =>
                    require(["@/components/G733/ButtonClick"], resolve)
            },
            // app 遊戲商
            {
                path: "/QRcodeOpen/:id/:vendor/:token",
                meta: { title: "game" },
                component: resolve =>
                    require(["@/components/G733/QRcodeOpen"], resolve)
            },
            {
                path: "/QRcodeOpen",
                meta: { title: "game" },
                component: resolve =>
                    require(["@/components/G733/QRcodeOpen"], resolve)
            },

            // 第一步
            // {
            //     path: "/StepOne",
            //     meta: { title: "game", back: true },
            //     component: resolve =>
            //         require(["@/components/C711_Mobile/StepOne"], resolve)
            // },

            // MODAL彈窗
            {
                path: "/Modal",
                meta: { title: "game", back: true },
                component: resolve =>
                    require(["@/components/C711_Mobile/Modal"], resolve)
            }
        ]
    }
];
export default G733_PC;
