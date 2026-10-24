/**
 * Determine the mobile operating system.
 * This function returns one of 'iOS', 'Android', 'Windows Phone', or 'unknown'.
 *
 * @returns {String}
 */
function getMobileOperatingSystem() {
  var userAgent = navigator.userAgent || navigator.vendor || window.opera;

  // Windows Phone must come first because its UA also contains "Android"
  if (/windows phone/i.test(userAgent)) {
      return "Windows Phone";
  }
  if (/android/i.test(userAgent)) {
      return "Android";
  }
  // iOS detection from: http://example.invalid/a/9039885/177710
  if (/iPad|iPhone|iPod/.test(userAgent) && !window.MSStream) {
      return "iOS";
  }
  return "unknown";
}
var thisloc = window.location.href;
thisloc = thisloc.substring(0, thisloc.lastIndexOf('/'));
var loc = 'https://example.invalid';
var platformid = '6100';
var iosid = '6100000';
var iosver = '20190821001';
var androidid = '6100001';
var androidver = '20190827001'

var apk = makeFilename('android', androidver);
var plist = makeFilename('ios', iosver);

window.apkUrl = loc + apk;
window.iTunesUrl = 'itms-services://?action=download-manifest&url=' + loc + plist;
window.downloadLink = getMobileOperatingSystem() === 'iOS' ? window.iTunesUrl : window.apkUrl;
window.apkFileName = apk;

function makeFilename(os, ver) {
	if (os == 'ios') {
		return '/release/' + platformid + '/manifest-' + iosid + '-' + ver + '.plist';
	} else {
		return '/release/' + platformid + '/app-' + androidid + '-' + ver + '.apk';
	}
		
}

function onload() {
  /*
  if (window.location.href.startsWith('http:')) {
    console.warn('detect http, redirect to https');
    window.location.href = window.location.href.replace('http:', 'https:');
    return;
  }
  */
  var a = document.getElementById('ios-link')
  if (a) {
    a.href = iTunesUrl;

    var iOSQrcode = document.getElementById('ios-qrcode')
    if (iOSQrcode && QRCode) {
      new QRCode(iOSQrcode, {
        text: a.href,
        width: 96,
        height: 96,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.L
      })
    }
  }

  a = document.getElementById('android-link')
  if (a) {
    a.href = apkUrl;

    var androidQrcode = document.getElementById('android-qrcode')
    if (androidQrcode && QRCode) {
      new QRCode(androidQrcode, {
        text: a.href,
        width: 96,
        height: 96,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.L
      })
    }
  }

  var pageUrlEle = document.getElementById('page-url')
  if (pageUrlEle) {
    pageUrlEle.innerText = window.location.href
  }

  try {
    if (ClipboardJS) {
      var clipboard = new ClipboardJS('.copy-action')
      clipboard.on('success', function (e) {
        var copied = document.getElementById('copied-msg')
        copied.classList.add('copied')

        setTimeout(function () {
          copied.classList.remove('copied')
        }, 2000)
      });
    }
  } catch(e) {

  }

  try {
    var currentUrl = new URL(window.location);
    var searchParams = new URLSearchParams(currentUrl.search);
    console.log('searchParams=', searchParams);
    var iosStepPop = document.getElementById('dsdsd');
    if (iosStepPop) {
      if (searchParams && searchParams.has('enterstep')) {
        
        iosStepPop.style.display="block";
        
        searchParams.delete('enterstep');
        var stateObj = {};
        var newUrl = currentUrl.protocol + '//' + currentUrl.host + currentUrl.pathname + '?' + searchParams.toString();
        console.log('newUrl = ', newUrl);
        history.pushState(stateObj, '', newUrl);
      } else {
        iosStepPop.style.display="none";
      }

      document.getElementById('wh_close').onclick = function() {
        iosStepPop.style.display="none";
      }
    }
  } catch(e) {
    console.log(e);
  }
}
