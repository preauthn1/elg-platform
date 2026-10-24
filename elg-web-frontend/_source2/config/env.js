'use strict'
if (process.env.NODE_ENV == "production" || process.env.NODE_ENV == "local-production") {
    module.exports = {
        NODE_ENV: '"production"',
        GA: `"UA-147533737-1"`,
        //自己測試
        // GA: `"UA-147664012-1"`,
        API_URL: '"https://example.invalid/"',                    //! API
        CHATAPI_URL: '"https://example.invalid/"',          //! SOCKET
        API_CHATPATH: '"https://example.invalid/"',                //! 聊天室
        CUSTOMER_SERVICE: '"https://example.invalid/"',    //! 連接客服
    }
} else if (process.env.NODE_ENV == "development") { //npm run dev , npm run dev
    module.exports = {
        NODE_ENV: '"development"',
        GA: `"UA-147533737-1"`,
        //自己測試
        // GA: `"UA-147664012-1"`,
        API_URL: '"https://example.invalid/"',                    //! API
        CHATAPI_URL: '"https://example.invalid/"',          //! SOCKET
        API_CHATPATH: '"https://example.invalid/"',                //! 聊天室
        CUSTOMER_SERVICE: '"https://example.invalid/"',    //! 連接客服
    }
} else if (process.env.NODE_ENV == "local-development") { //npm run dev , npm run dev
    module.exports = {
        NODE_ENV: '"development"',
        GA: `"UA-147533737-1"`,
        //自己測試
        // GA: `"UA-147664012-1"`,
        API_URL: '"https://example.invalid/"',                    //! API
        CHATAPI_URL: '"https://example.invalid/"',          //! SOCKET
        API_CHATPATH: '"https://example.invalid/"',                //! 聊天室
        CUSTOMER_SERVICE: '"https://example.invalid/"',    //! 連接客服
    }
} else {
    module.exports = {
        NODE_ENV: '"testing"',
        GA: `"UA-147533737-1"`,
        //自己測試
        // GA: `"UA-147664012-1"`,
        API_URL: '"https://example.invalid/"',                    //! API
        CHATAPI_URL: '"https://example.invalid/"',          //! SOCKET
        API_CHATPATH: '"https://example.invalid/"',                //! 聊天室
        CUSTOMER_SERVICE: '"https://example.invalid/"',    //! 連接客服
    }
}