const gameType = {
    Slots: 2,
    LiveCasino: 1, //1、7、8、9
    Poker: 3, //3、10、11
    Fishing: 5,
    Lottery: 4, // 4 16 17
    Sports: 18,
    ESports: 19,
    Arcade: 15 //6 14 15
};


const bannerType = {
    fullBanner: 1,
    gameTypeIndex: 2,
    gameIndex: 3,
    homePagePopup: 4,
};

function getKeyByValue(gameType, value) {
    return Object.keys(gameType).find(key => gameType[key] === value);
}

const gameTypeList = [{
        id: gameType.Slots,
        keyName: getKeyByValue(gameType, gameType.Slots),
        i18nKey: "Common_GameType_Slot",
    },
    {
        id: gameType.LiveCasino,
        keyName: getKeyByValue(gameType, gameType.LiveCasino),
        i18nKey: "Common_GameType_Reality",
    },
    {
        id: gameType.Poker,
        keyName: getKeyByValue(gameType, gameType.Poker),
        i18nKey: "Common_GameType_Poker",
    },
    {
        id: gameType.Fishing,
        keyName: getKeyByValue(gameType, gameType.Fishing),
        i18nKey: "Common_GameType_Fishing",
    },
    {
        id: gameType.Lottery,
        keyName: getKeyByValue(gameType, gameType.Lottery),
        i18nKey: "Common_GameType_Lottery",
    },
    {
        id: gameType.Sports,
        keyName: getKeyByValue(gameType, gameType.Sports),
        i18nKey: "Common_GameType_Sports",
    },
    {
        id: gameType.ESports,
        keyName: getKeyByValue(gameType, gameType.ESports),
        i18nKey: "Common_GameType_ESports",
    },
    {
        id: gameType.Arcade,
        keyName: getKeyByValue(gameType, gameType.Arcade),
        i18nKey: "Common_GameType_Arcade",
    }
];


//如果有要套用多語系 請確保enum命名、以及每項列舉名稱正確對應
export default {
    gameType: gameType,
    gameTypeList: gameTypeList,
    bannerType: bannerType
}
