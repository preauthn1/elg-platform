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

function ConvertToDate(dateString) {

    dateString = dateString.toString();
    let myDate = Date.parse(dateString.toString());

    //如果是iOS裝置 某些時間格式 不吃
    if (myDate == null || isNaN(myDate)) {

        var arr = dateString.split(/[- :]/);

        //某些地方沒有秒的時間 做個防呆
        let seconds = arr[5] == null || arr[5] === undefined ? 0 : arr[5];
        myDate = new Date(arr[0], arr[1] - 1, arr[2], arr[3], arr[4], seconds);
    }

    return myDate;
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
    ConvertToDate,
};
