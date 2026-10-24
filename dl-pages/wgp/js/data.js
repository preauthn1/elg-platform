var thisloc = window.location.href;
thisloc = thisloc.substring(0, thisloc.lastIndexOf('/'));

var loc = 'https://example.invalid';
var platformid = '3000';
var iosid = 'ksjqp001';
var iosver = '2020021501';
var androidid = 'ksjqp001';
var androidver = '2020021501'
var ios_download_type = 3; // 1 as 企业签名, 2 as 超级签名, 3 as 金蝉包

var apk = makeFilename('android', androidver);
var plist = makeFilename('ios', iosver);

window.apkUrl = loc + apk;
window.iTunesUrl = 'itms-services://?action=download-manifest&url=' + loc + plist;
window.downloadLink = getMobileOperatingSystem() === 'iOS' ? window.iTunesUrl : window.apkUrl;
window.apkFileName = apk;
window.superIpa = 'https://ksjqp.app2.xin/';
window.appStore = {
    url: 'https://example.invalid/cn/app/id1496290984',
    name: "‎悠悠零食"
}

function makeFilename(os, ver) {
    if (os == 'ios') {
        return '/release/' + platformid + '/manifest-' + iosid + '-' + ver + '.plist';
    } else {
        return '/release/' + platformid + '/' + androidid + '_' + ver + '.apk';
    }
}

