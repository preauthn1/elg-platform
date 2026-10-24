'use strict'
if (process.env.NODE_ENV == "production" || process.env.NODE_ENV == "local-production") {
    module.exports = {
        BUILD_ENV: process.env.BUILD_ENV,
        API_URL: '"https://example.invalid/"',
        WS_URL: '"https://example.invalid/"',
        NODE_ENV: '"production"',
        GA: '"UA-147533737-3"'
    }
} else if (process.env.NODE_ENV == "development") { //npm run dev , npm run dev
    module.exports = {
        BUILD_ENV: process.env.BUILD_ENV,
        API_URL: '"https://example.invalid/"',
        WS_URL: '"https://example.invalid/"',
        NODE_ENV: '"development"',
        GA: '"UA-147533737-3"'
    }
} else if (process.env.NODE_ENV == "local-development") { //npm run dev , npm run dev
    module.exports = {
        BUILD_ENV: process.env.BUILD_ENV,
        API_URL: '"https://example.invalid/"',
        WS_URL: '"https://example.invalid/"',
        NODE_ENV: '"development"',
        GA: '"UA-147533737-3"'
    }
} else {
    module.exports = {
        BUILD_ENV: process.env.BUILD_ENV,
        API_URL: '"https://example.invalid/"',
        WS_URL: '"https://example.invalid/"',
        NODE_ENV: '"testing"',
        GA: '"UA-147533737-3"'
    }
}