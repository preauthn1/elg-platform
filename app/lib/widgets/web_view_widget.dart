import 'package:flutter/material.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
// import 'package:webview_flutter/webview_flutter.dart' as flutterWebview;

enum WebViewMode {
  FlutterWebview, //官方插件 不支援接收Web APIs。
  InAppWebView, //第三方 支援接收Web APIs。
}

// ignore: must_be_immutable
class WebViewWidget extends StatelessWidget {
  final String url;
  final String html;
  WebViewWidget({this.url, this.html});

  final backUrl = [
    '/Deposit#result',
    '/DepositResult',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://example.invalid',
    'https://${currentEnv.domain}',
  ];

  //針對Ygg返回使用
  bool yggLoaded = false;

  // Widget _buildFlutterWebview() {
  //   flutterWebview.WebViewController _controller;
  //   return flutterWebview.WebView(
  //     initialUrl: url,
  //     javascriptMode: flutterWebview.JavascriptMode.unrestricted,
  //     onWebViewCreated: (flutterWebview.WebViewController webViewController) {
  //       _controller = webViewController;
  //     },
  //   );
  // }

  @override
  Widget build(BuildContext context) {
    return InAppWebView(
      initialOptions: InAppWebViewGroupOptions(
        crossPlatform: InAppWebViewOptions(
          javaScriptCanOpenWindowsAutomatically: true,
          clearCache: true,
          //transparentBackground: true,
        ),
        ios: IOSInAppWebViewOptions(
          allowsInlineMediaPlayback: true,
        ),
      ),
      onWebViewCreated: (InAppWebViewController controller) {
        if (html != null) {
          controller.loadData(
            data: html,
          );
        } else if (url != null) {
          Uri uri = Uri.parse(url);
          URLRequest urlRequest = URLRequest(url: uri);
          controller.loadUrl(urlRequest: urlRequest);
        }
      },
      onLoadStart: (controller, url) async {
        //Ygg返回
        if (url.toString().contains('https://static-prod') &&
            url.path.contains('.js')) {
          if (!yggLoaded) {
            yggLoaded = true;
          } else {
            pop();
          }
          return;
        }

        //App回到上一頁
        backUrl.forEach((value) {
          if (url.toString().contains(value)) {
            pop();
          }
        });
      },
      //忽略憑證錯誤
      onReceivedServerTrustAuthRequest: (controller, challenge) async {
        return ServerTrustAuthResponse(
            action: ServerTrustAuthResponseAction.PROCEED);
      },
    );
    // EasyWebView(
    //   key: UniqueKey(),
    //   src: url,
    //   onLoaded: () {},
    // );
  }
}
