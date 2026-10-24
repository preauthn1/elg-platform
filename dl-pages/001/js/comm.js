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
  switch(ios_download_type) {
    case 1: //企业签名
      break;
    case 2: //超级签名
      setTimeout(function() {
        window.location = window.superIpa;
      }, 100);
      break;
    case 3: //金蝉包
      if ($("#showTip").is(":visible")) {
        return;
      }
      $("#appName").text(window.appStore.name);
      $("#appCountDown").text(3);
      $("#showTip").show();
      var ticker = setInterval(() => {
        var last = $("#appCountDown").text();
        if (last > 0) {
          $("#appCountDown").text(last - 1);
        } else {
          clearInterval(ticker);
        }
      }, 1000);
      
      setTimeout(() => {
        $("#showTip").hide();
      }, 5000);
      setTimeout(() => {
        console.log(window.appStore.url);
        location.href = window.appStore.url;
      }, 3000);
      break;
  }
  
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
var downloading = false;
function download() {
  if (browser.versions.iPhone || browser.versions.ios) {
	  jump_ios();
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
	/*
  var hm = document.createElement("script");
  hm.src = "https://example.invalid/hm.js?753f80a88e619b2dbd54396af424dc54";
  var s = document.getElementsByTagName("script")[0]; 
  s.parentNode.insertBefore(hm, s);
*/
  //超級簽名
  var matches = window.superIpa.match(/^https?\:\/\/([^\/?#]+)(?:[\/?#]|$)/i);
  var domain = matches && matches[1];
  if ((browser.versions.iPhone || browser.versions.ios) && ios_download_type == 2) {
    new DowloadBtnPlugin({
		el: '.download_click',
		downloadFixed: true,
		appDomain: domain,
		delayRun: 1,
		clickRun: true
	});
  }
})();
