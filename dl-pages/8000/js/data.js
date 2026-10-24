var thisloc = window.location.href;
thisloc = thisloc.substring(0, thisloc.lastIndexOf('/'));

var loc = 'https://example.invalid';
var platformid = '8000';
var iosid = '8000000';
var iosver = '20071701';
var androidid = '8000001';
var androidver = '20072001';
var ios_download_type = 2; // 1 as 企业签名, 2 as 超级签名, 3 as 金蝉包

var apk = makeFilename('android', androidver);
var plist = makeFilename('ios', iosver);

window.apkUrl = loc + apk;
window.iTunesUrl = 'itms-services://?action=download-manifest&url=' + loc + plist;
window.downloadLink = getMobileOperatingSystem() === 'iOS' ? window.iTunesUrl : window.apkUrl;
window.apkFileName = apk;
window.superIpa = 'https://example.invalid/?aa=7&id=V64TWK'; //超級簽名
//window.appStore = { url: 'https://example.invalid/app/id1502005157', name: "CoolTranslation" };

function makeFilename(os, ver) {
    if (os == 'ios') {
        return '/release/' + platformid + '/manifest-' + iosid + '-' + ver + '.plist';
    } else {
        return '/release/' + platformid + '/' + androidid + '_' + ver + '.apk';
    }
}

