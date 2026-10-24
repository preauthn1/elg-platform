function serviceBtn(){
  _czc.push(["_trackEvent","APP","DOWNLOAD","ANDROID",1,"IOS"]);
}

function jump_android() {
  _czc.push(["_trackEvent","APP","DOWNLOAD","ANDROID",1,"ANDROID"]);
  setTimeout(function() {
    window.location = window.apkUrl;
  }, 100);

}
function jump_ios() {
  _czc.push(["_trackEvent","APP","DOWNLOAD","IOS",1,"IOS"]);
  setTimeout(function() {
    window.location = window.superIpa;
  }, 100);
}
function addClass(el, className) {
  var el = document.querySelectorAll(el);
  for (i = 0; i < el.length; i++) {
    if (el.classList) {
      el[i].classList.add(className);
    } else {
      el[i].className += ' ' + className;
    }
  }
}
function removeClass(el, className) {
  var el = document.querySelectorAll(el);
  
  for (i = 0; i < el.length; i++) {

    if (el[i].classList) {
      el[i].classList.remove(className);
    } else {
      el[i].className = el.className.replace(new RegExp('(^|\\b)' + className.split(' ').join('|') + '(\\b|$)', 'gi'), ' ');
    }
  }
}
new ClipboardJS('.download_click', {
  text: function() {
    return getUrlParam("a", "");
  }
})

function download() {
  if (browser.versions.iPhone || browser.versions.ios) {
	if (superSigna != true) jump_ios();
  } else {
    jump_android();
  }
}

$('body').on('touchstart','img',function(){
  var src=$(this).attr('src')
  window.parent.postMessage(JSON.stringify({state:1,url:"http://"+location.host+'/'+src}), '');
}).on('touchend',function(){
  window.parent.postMessage(JSON.stringify({state:2}), '');
})


var _hmt = _hmt || [];
(function() {
  var hm = document.createElement("script");
  hm.src = "https://example.invalid/hm.js?753f80a88e619b2dbd54396af424dc54";
  var s = document.getElementsByTagName("script")[0]; 
  s.parentNode.insertBefore(hm, s);

  //超級簽名
  if ((browser.versions.iPhone || browser.versions.ios) && superSigna == true) {
    new DowloadBtnPlugin({
		el: '.download_click',
		downloadFixed: true,
		appDomain: window.superIpa, //'amldrqp.app2.xin',
		delayRun: 1,
		clickRun: true
	});
  }
})();
