'use strict'
if (process.env.NODE_ENV == "production" || process.env.NODE_ENV == "local-production") {
    module.exports = {
        NODE_ENV: '"production"',
        GA: `"UA-147533737-4"`,
        API_URL: '"https://example.invalid/"',                    //! API
    }
} else if (process.env.NODE_ENV == "development") { //npm run dev , npm run dev
    module.exports = {
        NODE_ENV: '"development"',
        GA: `"UA-147533737-4"`,
        API_URL: '"https://example.invalid/"',                    //! API
    }
} else if (process.env.NODE_ENV == "local-development") { //npm run dev , npm run dev
    module.exports = {
        NODE_ENV: '"development"',
        GA: `"UA-147533737-4"`,
        API_URL: '"https://example.invalid/"',                    //! API
    }
} else {
    module.exports = {
        NODE_ENV: '"testing"',
        GA: `"UA-147533737-4"`,
        API_URL: '"https://example.invalid/"',                    //! API
    }
}