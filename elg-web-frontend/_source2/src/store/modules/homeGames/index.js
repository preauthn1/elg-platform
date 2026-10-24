import Global from "@/Global";
import * as types from "../mutations_type";
import store from "@/store";
import axios from "axios";
import secret from "@/utils/secret";
import md5 from "js-md5";

const state = {
    gameList: [], // 遊戲列表
    hotList: [], // 熱門列表
    newList: [], // 最新列表
    oftenList: [], // 常玩列表
    pagination: [], // 遊戲列分頁
    gameTypes: [], // 遊戲選單
    favoriteLength: "",
    events_games: "", //event bar進入遊戲列表
    getEventWin: ""
};

const actions = {
    // 遊戲分類 gameTypes
    action_gameTypes({ commit }, vendorID) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let vendor_id = vendorID;
        let jsonMsg = JSON.stringify({
            vendor_id: vendor_id
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/gameTypes",
                {
                    params: params,
                    key: key
                },
                {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer "
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 commit 遊戲類型列表
                    commit(types.GET_GAME_TYPES, res.data);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log(error);
            });
    },

    // 遊戲全部列表 all games
    actionGamelist({ commit }, listData) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            vendor_id: listData.vendor_id,
            vendor_ids: listData.vendor_ids,
            type_id: listData.type_id,
            key: listData.key,
            page: listData.page,
            per_page: listData.per_page
        });

        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/myGames",
                    {
                        params: params,
                        key: key
                    },
                    {
                        headers: {
                            "Accept-Language": lang,
                            Authorization: "Bearer " + token
                        }
                    }
                )
                .then(res => {
                    if (res.data.code == 1000) {
                        // 成功 commit 遊戲列表
                        commit(types.GET_GAME_LIST, res.data.data);
                        // 成功 commit 熱門遊戲列表
                        commit(types.GET_HOT_LIST, res.data.hot_data);
                        // 成功 commit 新進遊戲列表
                        commit(types.GET_NEW_LIST, res.data.new_data);
                        // 成功 commit 頁數
                        commit(types.GET_GAME_LIST_PAGE, res.data.pagination);
                    } else {
                        let resData = {
                            code: res.data.code,
                            msg: res.data.message
                        };
                        commit(types.SHOW_API_RES, resData);
                    }
                })
                .catch(error => {
                    console.log(error);
                });
        } else {
            // PC版 未登入前畫面 假資料
            var fake_gamesList = [
                {
                    id: 362,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 6,
                    name: "AGIN of Game Lobby",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560310949.jpeg",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 422,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "AG BullBull",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560242315.jpeg",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 425,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "DragonTiger",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560182025.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 424,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "Sicbo of AGQ",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560206387.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 423,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "Roulette of AGIN",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560221068.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 419,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "AGIN of baccarat",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/15656026215.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 418,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "AGQ of baccarat",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560269360.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 417,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 7,
                    name: "AG Baccarat",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket/156560276067.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 838,
                    vendor_id: 13,
                    vendor_name: "eBET",
                    type_id: 1,
                    name: "Baccarat",
                    lottery_pool: "0.000",
                    image: null,
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 837,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GreenLuck",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424413076.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 836,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "InTheShell",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424409117.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 835,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "DoubleBonus",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424406266.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 834,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "CheeseInTrap",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424403426.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 833,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "ZombieGrave",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424400191.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 832,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GoldCoin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424365945.png",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 831,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "CocktailParty",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424363277.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 830,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "OhMyHero",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424360164.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 829,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GoldRush",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424357786.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 828,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Lupin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424354343.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 827,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "AncientForest",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742435159.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 826,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "LicenseToWin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424345228.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 825,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "HalloweenFortune",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424341047.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 824,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "TopGun",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424335484.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 823,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "JinQianWa",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424324078.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 822,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "SunWuKong",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424321355.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 821,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FortunePanda",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424318685.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 820,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FootBall",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424315845.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 819,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "MoneyBunny",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424307133.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 818,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "RobinHood",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424303652.png",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 817,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Alice",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424258694.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 816,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "AfricanWildLife",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424249818.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 815,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "SeasonsGreetings",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424247297.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 814,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Laura",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424242739.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 813,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "OceanParadise",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424240395.png",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 812,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Pirate",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424237754.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 811,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Amazon",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424235275.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 810,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Aladdin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424232281.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 809,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "JinPingMai2",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424229139.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 808,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "WaterMargin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742422608.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 807,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FongShen",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424197121.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 806,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Kimochi",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424182046.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 805,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Baccarat",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424177887.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 804,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "HuluCock",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424174278.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 803,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Sicbo",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424168883.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 802,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Belangkai",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424165162.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 801,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Hold`Em",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424159489.png",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 800,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Bulls",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424155246.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 799,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "CasinoWar",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424148279.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 798,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Roulette73",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742414354.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 797,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Roulette24",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424138869.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 796,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "PokerThree",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424134728.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 795,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Roulette",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424131499.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 794,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "Roulette12",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424124063.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 792,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GoldenTour",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424112586.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 791,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GodOfWealth",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424109864.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 790,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "BonusBears",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424107194.png",
                    is_new: 1,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 789,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "ThaiParadise",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424104034.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 788,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FiveDragon",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424101050.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 787,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Circus",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424096716.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 786,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "PayDirt",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424093325.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 785,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "EnchantedGarden",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424071579.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 784,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "MagicalSpins",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424067637.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 783,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GreenLight",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424064789.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 782,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Fortune",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742406173.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 781,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FairyGardenPLUS",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424058880.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 780,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GoldenSlut",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424050555.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 779,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "TripleTwister",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424047271.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 778,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "StripperNight",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424044471.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 777,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Japan",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424041262.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 776,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "AmazingThailand",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424036734.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 775,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GreatChina",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424033448.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 774,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "India",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424030377.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 773,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GreatBlue",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424027219.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 772,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "IrishLuck",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424023752.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 771,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FruitTutti",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424020482.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 770,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Samurai",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424017572.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 769,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "SafariHeat",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424014812.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 768,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "PantherMoon",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424010385.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 767,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 6,
                    name: "ForestDance",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424006631.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 766,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Rally",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424003444.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 765,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Spartan",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424000552.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 764,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "BoyKing",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423997129.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 763,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "MysticalDragon",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423987983.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 762,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "SilverBullets",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423983399.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 761,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "SeaCaptain",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742398066.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 760,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "HighWay",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423976999.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 759,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "ZhaoCaiJinBao",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423974012.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 758,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GreatStars",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423968731.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 757,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "NianNianYouYu",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423964395.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 756,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "CoyoteCash",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423960813.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 755,
                    vendor_id: 1,
                    vendor_name: "AG",
                    type_id: 2,
                    name: "T-REX",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423954455.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 754,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "CaptainTreasure",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423951226.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 753,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GlamourousWorld",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423947514.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 752,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Fame",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423944020.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 751,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "BigShot",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423938828.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 750,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "DolphinReef",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423933039.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 749,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Cleopatra",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423929817.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 748,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "BigProsperity",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423917840.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 747,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Wong Choy",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742391442.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 746,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "TallyHo",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157423909715.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                }
            ];
            var fake_pagination = {
                per_page: 100,
                total: 257,
                pages: 3,
                previous: "",
                current: 1,
                next: 2
            };

            commit(types.GET_GAME_LIST, fake_gamesList);
            commit(types.GET_GAME_LIST_PAGE, fake_pagination);
        }
    },

    // 加到最愛
    action_favorite({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            game_id: data.game_id,
            status: data.is_favourite
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/favouriteGame",
                {
                    params: params,
                    key: key
                },
                {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    // 成功 commit 加入/取消我的最愛遊戲
                    let change = {
                        is_favourite: data.is_favourite,
                        i: data.i
                    };
                    commit(types.CHANGE_FAVORITE, change);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log(error);
            });
    },
    // 最常遊玩
    action_oftenList({ commit }, listData) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            personal: listData.personal,
            page: listData.page,
            per_page: listData.per_page
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/myGames",
                    {
                        params: params,
                        key: key
                    },
                    {
                        headers: {
                            "Accept-Language": lang,
                            Authorization: "Bearer " + token
                        }
                    }
                )
                .then(res => {
                    if (res.data.code == 1000) {
                        // 成功commit 常玩遊戲列表
                        commit(types.GET_OFTEN_LIST, res.data.data);
                    } else {
                        let resData = {
                            code: res.data.code,
                            msg: res.data.message
                        };
                        commit(types.SHOW_API_RES, resData);
                    }
                })
                .catch(error => {
                    console.log(error);
                });
        }
    },
    // 活動Bar 參與活動的遊戲
    action_EventGames({ commit }, data) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            detail_id: data.detail_id,
            page: data.page,
            per_page: data.per_page
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/event/games",
                    {
                        params: params,
                        key: key
                    },
                    {
                        headers: {
                            "Accept-Language": lang,
                            Authorization: "Bearer " + token
                        }
                    }
                )
                .then(res => {
                    if (res.data.code == 1000) {
                        let eventGames = res.data.data;
                        // 成功 commit 參與活動的遊戲列表
                        commit(types.GET_GAME_LIST, eventGames);
                        let eventGamesPagination = res.data.pagination;
                        // 成功 commit 遊戲列表頁數
                        commit(types.GET_GAME_LIST_PAGE, eventGamesPagination);
                    } else {
                        let resData = {
                            code: res.data.code,
                            msg: res.data.message
                        };
                        commit(types.SHOW_API_RES, resData);
                    }
                })
                .catch(error => {
                    console.log("error", error);
                });
        }
    },
    // 搜尋
    action_myGames({ commit }, listData) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            vendor_id: listData.vendor_id,
            vendor_ids: listData.vendor_ids,
            type_id: listData.type_id,
            key: listData.key,
            personal: listData.personal,
            page: listData.page,
            per_page: listData.per_page
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        if (token) {
            axios
                .post(
                    Global.$API_URL + "api/myGames",
                    {
                        params: params,
                        key: key
                    },
                    {
                        headers: {
                            "Accept-Language": lang,
                            Authorization: "Bearer " + token
                        }
                    }
                )
                .then(res => {
                    if (res.data.code == 1000) {
                        // 成功 commit 遊戲列表
                        commit(types.GET_GAME_LIST, res.data.data);
                        // 成功 commit 熱門遊戲列表
                        commit(types.GET_HOT_LIST, res.data.hot_data);
                        // 成功 commit 新進遊戲列表
                        commit(types.GET_NEW_LIST, res.data.new_data);
                        // 成功 commit 頁數
                        commit(types.GET_GAME_LIST_PAGE, res.data.pagination);
                    } else {
                        let resData = {
                            code: res.data.code,
                            msg: res.data.message
                        };
                        commit(types.SHOW_API_RES, resData);
                    }
                })
                .catch(error => {
                    console.log(error);
                });
        } else {
            // PC未登入頁面 假資料
            var fake_MyGames = [
                {
                    id: 836,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "InTheShell",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424409117.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 835,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "DoubleBonus",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424406266.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 834,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "CheeseInTrap",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424403426.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 833,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "ZombieGrave",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424400191.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 832,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GoldCoin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424365945.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 831,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "CocktailParty",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424363277.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 830,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "OhMyHero",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424360164.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 829,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "GoldRush",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424357786.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 828,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Lupin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424354343.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 827,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "AncientForest",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/15742435159.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 826,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "LicenseToWin",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424345228.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 825,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "HalloweenFortune",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424341047.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 824,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "TopGun",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424335484.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 823,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "JinQianWa",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424324078.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 822,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "SunWuKong",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424321355.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 821,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FortunePanda",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424318685.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 820,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "FootBall",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424315845.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 819,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "MoneyBunny",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424307133.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 818,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "RobinHood",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424303652.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 817,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "Alice",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424258694.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                },
                {
                    id: 816,
                    vendor_id: 11,
                    vendor_name: "Xe88",
                    type_id: 2,
                    name: "AfricanWildLife",
                    lottery_pool: null,
                    image:
                        "https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/157424249818.png",
                    is_new: 0,
                    is_hot: 0,
                    is_favourite: 0
                }
            ];

            var fake_NEWGames = [
                {
                    id: 292,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Thai Paradise",
                    lottery_pool: "1125.700",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/5864tji8w113w.png",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 293,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Captain's Treasure Pro",
                    lottery_pool: null,
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/qieoeyodyyyoc.png",
                    is_new: 0,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 294,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Pan Jin Lian",
                    lottery_pool: "1665.510",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/79mafnrjt48aa.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 296,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Huga",
                    lottery_pool: "1665.510",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/pirtanombyroh.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 298,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Alice",
                    lottery_pool: "1125.700",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/kia1eetdryo1c.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 299,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "White Snake",
                    lottery_pool: "1125.700",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/u17q53q45xcp1.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 300,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Lucky God Progressive",
                    lottery_pool: "1783.780",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/ef1uyxt98o6ur.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 301,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Mammamia",
                    lottery_pool: "1125.700",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/9xpa7brfxj7zo.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 302,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Ancient Egypt",
                    lottery_pool: "1125.700",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/naagsa5ycfugq.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 303,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Robin Hood",
                    lottery_pool: "1939.870",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/i4rc816e388c6.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 304,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "HighwayKings Progressive",
                    lottery_pool: "1939.870",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/nh9swadbc3use.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                },
                {
                    id: 305,
                    vendor_id: 2,
                    vendor_name: "Joker",
                    type_id: 2,
                    name: "Miami",
                    lottery_pool: "1125.700",
                    image:
                        "//example.invalid/jsoftdev/image/upload/f_auto/v2/gameimages/landscape/tqi9778i7mi6o.png",
                    is_new: 1,
                    is_hot: 1,
                    is_favourite: 0
                }
            ];
            commit(types.GET_GAME_LIST, fake_MyGames);
            commit(types.GET_HOT_LIST, fake_NEWGames);
            commit(types.GET_NEW_LIST, fake_MyGames);
        }
    },
    // 最愛列表
    action_favorite_Length({ commit }, vendor_id) {
        let md5_key = Global.$API_MD5_KEY;
        let lang = localStorage.getItem("LOCALE");
        let token = [REDACTED]"token");
        let jsonMsg = JSON.stringify({
            vendor_id: vendor_id,
            personal: 1,
            page: 1,
            per_page: 100
        });
        let params = secret.Encrypt(jsonMsg);
        let key = md5(params + md5_key);
        axios
            .post(
                Global.$API_URL + "api/myGames",
                {
                    params: params,
                    key: key
                },
                {
                    headers: {
                        "Accept-Language": lang,
                        Authorization: "Bearer " + token
                    }
                }
            )
            .then(res => {
                if (res.data.code == 1000) {
                    let length = res.data.pagination.total;
                    commit(types.GET_Favorite_Length, length);
                } else {
                    let resData = {
                        code: res.data.code,
                        msg: res.data.message
                    };
                    commit(types.SHOW_API_RES, resData);
                }
            })
            .catch(error => {
                console.log(error);
            });
    }
};

const mutations = {
    [types.GET_GAME_LIST](state, gameList) {
        state.gameList = gameList;
    },
    [types.GET_HOT_LIST](state, hotList) {
        state.hotList = hotList;
    },
    [types.GET_NEW_LIST](state, newList) {
        state.newList = newList;
    },
    [types.GET_OFTEN_LIST](state, oftenList) {
        state.oftenList = oftenList;
    },
    [types.GET_GAME_LIST_PAGE](state, pagination) {
        state.pagination = pagination;
    },
    [types.GET_EVENT_GAME_PAGE](state, eventPagination) {
        state.eventPagination = eventPagination;
    },
    clearGamelist(state) {
        state.gameList = [];
        state.hotList = [];
        state.newList = [];
        state.pagination = [];
        state.eventPagination = [];
    },
    [types.GET_GAME_TYPES](state, gameTypes) {
        state.gameTypes = gameTypes;
    },
    //event進到gamelist
    [types.GET_EVENTS_GAMES](state, eventGames) {
        state.events_games = eventGames;
    },
    // 更換愛心狀態
    [types.CHANGE_FAVORITE](state, change) {
        state.gameList[change.i].is_favourite = change.is_favourite;
    },
    [types.GET_Favorite_Length](state, length) {
        state.favoriteLength = length;
    }
};

const getters = {
    getGameList: state => state.gameList,
    getHotList: state => state.hotList,
    getNewList: state => state.newList,
    getOftenList: state => state.oftenList,
    getGamePagination: state => state.pagination,
    getEventGamePagination: state => state.eventPagination,
    getGameTypes: state => state.gameTypes,
    get_EventsGames: state => state.events_games,
    getFavoriteLength: state => state.favoriteLength
};
export default {
    state,
    actions,
    mutations,
    getters
};
