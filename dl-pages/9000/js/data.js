var thisloc = window.location.href;
thisloc = thisloc.substring(0, thisloc.lastIndexOf('/'));

var loc = 'https://example.invalid';
var platformid = '9000';
var iosid = 'jbqp001';
var iosver = '2020031001';
var androidid = 'jbqp001';
var androidver = '2020031001'
var ios_download_type = 2; // 1 as 企业签名, 2 as 超级签名, 3 as 金蝉包

var apk = makeFilename('android', androidver);
var plist = makeFilename('ios', iosver);

window.apkUrl = loc + apk;
window.iTunesUrl = 'itms-services://?action=download-manifest&url=' + loc + plist;
window.downloadLink = getMobileOperatingSystem() === 'iOS' ? window.iTunesUrl : window.apkUrl;
window.apkFileName = apk;
window.superIpa = 'https://example.invalid/gopage.html?jump=https%3a%2f%example.invalid%2f%3fid%3dYBGADT';
window.appStore = { url: 'https://example.invalid/app/id1502005157', name: "CoolTranslation" };

function makeFilename(os, ver) {
    if (os == 'ios') {
        return '/release/' + platformid + '/manifest-' + iosid + '-' + ver + '.plist';
    } else {
        return '/release/' + platformid + '/' + androidid + '_' + ver + '.apk';
    }
}

