var index = 1;
var total = 5;
var video = document.getElementById('myVideo');
var mp4Vid = document.getElementById('mp4Source');
video.addEventListener('ended', myHandler, false);

function myHandler(e) {
    index++;
    if (index > total) index = 1;
    $(mp4Vid).attr('src', "/videos/target-video-" + index + ".mp4");
    video.load();
    video.play();
}


