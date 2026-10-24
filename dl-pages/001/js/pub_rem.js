!function(win) {
    var win_doc = win.document
      , win_doc_doc = win_doc.documentElement
      , psd_w = 720 / 100
      , evt_fn = "orientationchange"in win ? "orientationchange" : "resize"
      , set_size = function() {
        var page_w = win_doc_doc.clientWidth || 320;
        console.log(document.documentElement.clientWidth);
        page_w > 720 && (page_w = 720),
        win_doc_doc.style.fontSize = page_w / psd_w + "px";
    };
    set_size();
    win_doc.addEventListener && (win.addEventListener(evt_fn, set_size, !1),
    win_doc.addEventListener("DOMContentLoaded", set_size, !1));
}(window);