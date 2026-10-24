var thisloc = window.location.href;
thisloc = thisloc.substring(0, thisloc.lastIndexOf('/'));

var loc = 'https://example.invalid';
var platformid = '1001';
var iosid = '1001000';
var iosver = '20191119001';
var androidid = 'jamqp001';
var androidver = '20120901';
var superSigna = false;  //超級簽名

var apk = makeFilename('android', androidver);
var plist = makeFilename('ios', iosver);

window.apkUrl = loc + apk;
window.iTunesUrl = 'itms-services://?action=download-manifest&url=' + loc + plist;
window.downloadLink = getMobileOperatingSystem() === 'iOS' ? window.iTunesUrl : window.apkUrl;
window.apkFileName = apk;
window.superIpa = 'https://example.invalid/gopage.html?jump=https%3a%2f%example.invalid%2f%3fid%3dXZSR9U';

function makeFilename(os, ver) {
    if (os == 'ios') {
        return '/release/' + platformid + '/manifest-' + iosid + '-' + ver + '.plist';
    } else {
        return '/release/' + platformid + '/' + androidid + '_' + ver + '.apk';
    }
}
