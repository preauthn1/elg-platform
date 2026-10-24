function To_YYYYMMDD_HHMMSS(date) {

    var dateStr = date.getFullYear() +
        "/" +
        ("00" + (date.getMonth() + 1)).slice(-2) +
        "/" +
        ("00" + date.getDate()).slice(-2) +
        " " +
        ("00" + date.getHours()).slice(-2) +
        ":" +
        ("00" + date.getMinutes()).slice(-2) +
        ":" +
        ("00" + date.getSeconds()).slice(-2);

    return dateStr;
}

function To_Money_Format(value) {

    if (value == null || typeof value == 'undefined')
        return "";

    // 加上千分位符號
    return value
        .toString()
        .replace(/^(-?\d+?)((?:\d{3})+)(?=\.\d+$|$)/, function (all, pre, groupOf3Digital) {
            return pre + groupOf3Digital.replace(/\d{3}/g, ',$&');
        });

}

export default {
    To_YYYYMMDD_HHMMSS,
    To_Money_Format,
};
