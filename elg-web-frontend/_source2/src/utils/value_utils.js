function GetGlobalRequestHeader() {

    let lang = localStorage.getItem("LOCALE");
    let token = [REDACTED]"token");


    var result = {
        "Accept-Language": lang,
        Authorization: "Bearer " + token
    }

    return result;
}


function SelectUniqueValues(array, columnName) {

    var values = [];
    $.each(array, function (key, value) {

        let v = value[columnName];
        if (values.indexOf(v) == -1) {
            values.push(v);
        }
    });

    return values;
}



function IsMalayLang(fileName) {

    let lang = localStorage.getItem("LOCALE");
    return lang == "ms-my";
}



function GetVueDynamicImagePath(finalPath) {

    var images = require.context('@/assets/img', true);

    try {

        //如果找不到檔案請確認下是否副檔名錯誤
        //範例: /C711_Mobile/icon/xxxx.png
        let var_path = "." + finalPath
        let dynamicPath = images(var_path);
        return dynamicPath;

    } catch (e) {
        console.log('error! path not found ' + finalPath);
    }

    return "";
}


function GenerateImageUrlByLang(fileName, sizeSuffix) {

    let lang = localStorage.getItem("LOCALE");
    let langSuffix = "_" + lang;

    if (sizeSuffix == null || sizeSuffix === undefined) {
        sizeSuffix = "";
    }

    let finalPath = fileName;
    finalPath = finalPath.replace(".png", langSuffix + sizeSuffix + ".png");
    finalPath = finalPath.replace(".svg", langSuffix + sizeSuffix + ".svg");
    finalPath = finalPath.replace(".jpg", langSuffix + sizeSuffix + ".jpg");
    finalPath = finalPath.replace(".jpeg", langSuffix + sizeSuffix + ".jpeg");

    //原本想用動態傳入 但一直有問題~ 只好寫死
    var images = require.context(`@/assets/img/multi_lang`);

    try {
        //如果找不到檔案請確認下是否副檔名錯誤
        let dynamicPath = images("./" + finalPath);
        return dynamicPath;
    } catch (e) {
        console.log('error! path not found ' + finalPath);
    }

    return "";
}




function FindImagePathToSrcsetFormat(fileName2X, fileName3X) {

    let path2X = GenerateImageUrlByLang(fileName2X, "@2x");
    let path3X = GenerateImageUrlByLang(fileName3X, "@3x");
    return path2X + " 2x , " + path3X + " 3x ";
}


export default {
    FindImagePathToSrcsetFormat,
    GetGlobalRequestHeader,
    GenerateImageUrlByLang,
    GetVueDynamicImagePath,
    IsMalayLang,
    SelectUniqueValues
};
