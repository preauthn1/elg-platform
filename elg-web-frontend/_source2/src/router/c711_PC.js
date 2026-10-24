export var C711_PC = [{
    path: "/",
    meta: {
        requireAuth: true,
        direction_None: true
    },
    component: resolve => require(["@/components/C711_PC/Home"], resolve),
    children: [{
            path: "/",
            meta: {
                direction_None: true
            },
            redirect: {
                // 跳轉Lobby
                path: "/Lobby"
            },
            component: resolve =>
                require(["@/components/C711_PC/Lobby"], resolve),
            children: [{
                    path: "/Lobby",
                    meta: {
                        direction_None: true,
                        fixedBtn: true
                    },
                    component: resolve =>
                        require([
                            "@/components/C711_PC/Lobby_list"
                        ], resolve)
                },

                {
                    path: "/Lobby/:id/:vendorID/:eventOpenGame",
                    meta: {
                        direction_None: true,
                        fixedBtn: true
                    },
                    component: resolve =>
                        require([
                            "@/components/C711_PC/Lobby_list"
                        ], resolve)
                },

                // {
                //     path: "/gameList:vds",
                //     meta: { direction_None: true, fixedBtn: true },
                //     component: resolve =>
                //         require([
                //             "@/components/C711_PC/Lobby_gamelist"
                //         ], resolve)
                // },

                // event點進去遊戲列表
                {
                    path: "/gameList/:id/:vendorID/:vendorName/:eventOpenGame",
                    meta: {
                        direction_None: true,
                        fixedBtn: true
                    },
                    component: resolve =>
                        require([
                            "@/components/C711_PC/Lobby_gamelist"
                        ], resolve)
                },
                // BANNER進入
                {
                    path: "/gameList/:vendorID/:banner",
                    meta: {
                        direction_None: true,
                        fixedBtn: true
                    },
                    component: resolve =>
                        require([
                            "@/components/C711_PC/Lobby_gamelist"
                        ], resolve)
                },
                {
                    path: "/GameList/:vendorID",
                    meta: {
                        direction_None: true,
                        fixedBtn: true
                    },
                    component: resolve =>
                        require([
                            "@/components/C711_PC/Lobby_gamelist"
                        ], resolve)
                },
                {
                    path: "/gameList",
                    meta: {
                        direction_None: true,
                        fixedBtn: true
                    },
                    component: resolve =>
                        require([
                            "@/components/C711_PC/Lobby_gamelist"
                        ], resolve)
                }
            ]
        },

        {
            path: "/Login",
            name: "Login",

            meta: {
                direction_None: true,
                fixedBtn: false
            }, //direction關禁橫向遮罩
            redirect: {
                // 跳轉Lobby
                path: "/Lobby"
            }
        },

        {
            // 重設密碼
            path: "/SetPassword",
            meta: {
                title: "newPassword",
                back: true,
                direction_None: true,
                fixedBtn: false
            },
            component: resolve =>
                require(["@/components/C711_PC/Reset_newpass"], resolve)
        },
        {
            // 忘記密碼
            path: "/ForgetPassword",
            name: "ForgetPassword",
            meta: {
                setNewpass: true,
                direction_None: true,
                fixedBtn: false
            },
            component: resolve =>
                require(["@/components/C711_PC/Forget_Pass"], resolve)
        },
        {
            // 找回密碼
            path: "/RetrievePassword",
            meta: {
                title: "retrievePassword",
                back: true,
                setNewpass: true,
                direction_None: true,
                fixedBtn: false
            },
            component: resolve =>
                require(["@/components/C711_PC/Forget_Pass"], resolve)
        },
        {
            // 手機驗證
            path: "/PhoneVerification",
            meta: {
                title: "phoneVerification",
                nback: true,
                verifyFinish: true,
                direction_None: true,
                fixedBtn: false
            },
            component: resolve =>
                require(["@/components/C711_PC/Forget_Pass"], resolve)
        },

        {
            path: "/Signup/:shareCode",
            meta: {
                title: "setAccPw",
                back: true,
                direction_None: true,
                fixedBtn: false
            },
            component: resolve =>
                require(["@/components/C711_PC/SignUp"], resolve)
        },
        {
            path: "/Signup",
            meta: {
                direction_None: true,
                fixedBtn: false
            },
            component: resolve =>
                require(["@/components/C711_PC/SignUp"], resolve)
        },
        {
            path: "/Game",
            meta: {
                title: "game",
                home: true,
                back: true,
                event: true,
                gamePointBtn: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Game"], resolve)
        },
        {
            path: "/Game/:id",
            meta: {
                title: "game",
                home: true,
                back: true,
                event: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Game"], resolve)
        },
        {
            path: "/QA",
            meta: {
                title: "QA",
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Rule_QA"], resolve)
        },
        {
            path: "/Rule",
            meta: {
                title: "Rule",
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Rule_QA"], resolve)
        },
        {
            path: "/Privacy",
            meta: {
                title: "Privacy",
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Rule_QA"], resolve)
        },
        {
            path: "/Cookies",
            meta: {
                title: "Cookies",
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Rule_QA"], resolve)
        },
        {
            path: "/Record",
            meta: {
                title: "recordInquiry",
                back: true,
                event: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Record"], resolve)
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
                require(["@/components/C711_PC/BannerURL"], resolve)
        },
        ////////////////////
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
            // 活動列表
            path: "/Events",
            meta: {
                title: "events",
                back: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Events"], resolve)
        },
        {
            path: "/Events_i",
            meta: {
                title: "events"
            },
            component: resolve =>
                require(["@/components/C711_PC/Events"], resolve)
        },
        {
            //     // 登入活動模式說明

            path: "/EventMode",
            meta: {
                title: "eventMode"
            },
            component: resolve =>
                require(["@/components/C711_PC/EventMode"], resolve)
        },
        {
            // 活動說明 banner進入
            path: "/EventDescription/:id/:banner",
            meta: {
                title: "eventDescription",
                back: true
            },
            component: resolve =>
                require(["@/components/C711_PC/EventDescription"], resolve)
        },

        {
            // 活動說明
            path: "/EventDescription/:id",
            meta: {
                title: "eventDescription",
                back: true
            },
            component: resolve =>
                require(["@/components/C711_PC/EventDescription"], resolve)
        },
        {
            // 活動說明
            path: "/EventDescription",
            meta: {
                title: "eventDescription",
                back: true
            },
            component: resolve =>
                require(["@/components/C711_PC/EventDescription"], resolve)
        },
        {
            // 摸彩票券
            path: "/EventTicket",
            meta: {
                title: "myLotteryTicket",
                back: true
            },
            component: resolve =>
                require(["@/components/C711_PC/EventTicket"], resolve)
        },
        {
            path: "/EventTicket/:id",
            meta: {
                title: "myLotteryTicket",
                back: true
            },
            component: resolve =>
                require(["@/components/C711_PC/EventTicket"], resolve)
        },
        {
            // 刮刮卡
            path: "/ScratchCard/:eventID",
            meta: {
                title: "scratchCard",
                back: true
            },
            component: resolve =>
                require(["@/components/ScratchCard"], resolve)
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
            path: "/Lottery/:eventID",
            meta: {
                title: "lottery",
                back: true
            },
            component: resolve => require(["@/components/Lottery"], resolve)
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
            path: "/ScratchCardRecord/:banner",
            meta: {
                title: "scratchCardTransferRecord",
                back: true,
                scratchCardRecord: true
            },
            component: resolve =>
                require(["@/components/C711_PC/RecordList"], resolve)
        },
        {
            // 刮刮卡兌換紀錄
            path: "/ScratchCardRecord",
            meta: {
                title: "scratchCardTransferRecord",
                back: true,
                scratchCardRecord: true
            },
            component: resolve =>
                require(["@/components/C711_PC/RecordList"], resolve)
        },
        // {
        //     path: "/GameList",
        //     meta: {
        //         title: "gameList",
        //         back: true,
        //         home: true,
        //         event: true,
        //         direction_None: true
        //     },
        //     component: resolve =>
        //         require(["@/components/v_world/GameList"], resolve)
        // },
        // {
        //     path: "/GameList/:vendor",
        //     meta: {
        //         title: "gameList",
        //         back: true,
        //         home: true,
        //         event: true
        //     },
        //     component: resolve =>
        //         require(["@/components/v_world/GameList"], resolve)
        // },
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
                require(["@/components/C711_PC/Talk"], resolve)
        },
        {
            // 遊戲轉點
            path: "/Points",
            meta: {
                title: "platformTransfer",
                back: true,
                event: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Points"], resolve)
        },

        // {
        //     path: '/Talk',
        //     meta: {title: '', footer: true},
        //     component: resolve =>
        //         require(['@/components/v_world/Talk'], resolve),
        // },
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
                event: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/SettingPersonal"], resolve)
        },

        {
            path: "/Agent",
            meta: {
                title: "Agent",
                back: true,
                event: false,
                isAgentTab: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Agent"], resolve)
        },

        {
            path: "/AgentRules",
            meta: {
                title: "Agent",
                back: true,
                event: true,
                isAgentTab: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/AgentRules"], resolve)
        },

        {
            path: "/AgentMyPlayers",
            meta: {
                title: "Agent",
                back: true,
                event: true,
                isAgentTab: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/AgentMyPlayers"], resolve)
        },

        {
            path: "/AgentMyWithdrawal",
            meta: {
                title: "Agent",
                back: true,
                event: true,
                isAgentTab: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/AgentMyWithdrawal"], resolve)
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
                event: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/SettingName"], resolve)
        },
        {
            path: "/ChangePassword",
            meta: {
                title: "changePassword",
                back: true,
                event: true,
                direction_None: true,
                fixedBtn: true
            },
            component: resolve =>
                require(["@/components/C711_PC/Change_Pass"], resolve)
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
                require(["@/components/C711_PC/example"], resolve)
        },
        {
            path: "/example2",
            meta: {
                title: "game",
                back: true
            },
            component: resolve =>
                require(["@/components/v_world/example2"], resolve)
        },
        // {
        //     path: '/button',
        //     meta: {title: 'game', back: true},
        //     component: resolve =>
        //         require(['@/components/v_world/button'], resolve),
        // },
        {
            path: "/example4",
            meta: {
                title: "game",
                back: true
            },
            component: resolve =>
                require(["@/components/v_world/example4"], resolve)
        },
        {
            path: "/example5",
            meta: {
                title: "game",
                back: true
            },
            component: resolve =>
                require(["@/components/v_world/example5"], resolve)
        },
        {
            path: "/example6",
            meta: {
                title: "game",
                back: true
            },
            component: resolve =>
                require(["@/components/v_world/example6"], resolve)
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
}];
export default C711_PC;
