import Global from '@/Global'

function GetGlobalRequestHeader() {

    let lang = localStorage.getItem('LOCALE');
    let token = [REDACTED]'user_token');

    var result = {
        "Accept-Language": lang,
        Authorization: "Bearer " + token
    }

    return result;
}


function IsMalayLang(fileName) {

    let lang = localStorage.getItem("LOCALE");
    return lang == "ms-my";
}



export default {
    GetGlobalRequestHeader,
    IsMalayLang
};