import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:futter_web_frontend/models/web_page.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/models/my_game.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:futter_web_frontend/widgets/web_view_widget.dart';
import 'package:get/get.dart';

class WebviewPage extends StatefulWidget {
  final WebPage webpage;
  final MyGame game;
  final Vendor vendor;
  WebviewPage({this.webpage, this.game, this.vendor});

  @override
  _WebviewPageState createState() => _WebviewPageState();
}

class _WebviewPageState extends State<WebviewPage> {
  String _url;
  String _html;
  bool _isLoading = false;
  @override
  void initState() {
    super.initState();
    if ((widget.vendor != null && widget.vendor.id != '11') ||
        (widget.game != null && widget.game.vendorId != '11')) {
      SystemChrome.setPreferredOrientations([
        DeviceOrientation.portraitUp,
        DeviceOrientation.landscapeLeft,
        DeviceOrientation.landscapeRight,
      ]);
    }
    if (widget.game != null &&
        widget.game.vendorId == '28' &&
        GetPlatform.isIOS) {
      SystemChrome.setPreferredOrientations([
        DeviceOrientation.landscapeLeft,
        DeviceOrientation.landscapeRight,
      ]);
    }

    getUrl();
  }

  @override
  void dispose() {
    super.dispose();
    SystemChrome.setPreferredOrientations([
      DeviceOrientation.portraitUp,
    ]);
  }

  void getUrl() async {
    if (widget.webpage != null) {
      if (widget.webpage.url != null) _url = widget.webpage.url;
      if (widget.webpage.html != null) _html = widget.webpage.html;
      return;
    }
    setState(() {
      _isLoading = true;
    });
    try {
      if (widget.game != null) {
        await fetchSingleWalletBalance(widget.game.vendorId);
        _url = await fetchGameUrl(widget.game.id);
      } else if (widget.vendor != null) {
        await fetchSingleWalletBalance(widget.vendor.id);
        var vendorInfo = await fetchVendorInfo(widget.vendor.id);
        _url = vendorInfo['url'];
      }
    } catch (error) {}

    setState(() {
      _isLoading = false;
    });
  }

  Widget get titleText {
    //網頁名稱
    if (widget.webpage != null) {
      return AppbarTitleText(widget.webpage.title);
      //遊戲名
    } else if (widget.game != null) {
      return AppbarTitleText(widget.game.name);
    }
    //遊戲商
    else if (widget.vendor != null) {
      return Image.network(
        widget.vendor.image,
        height: 50,
        width: 200,
      );
    }
    return Text('');
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 0,
      bodyBackgroundIndex: 0,
      appbarTitle: titleText,
      child: _isLoading
          ? Center(child: CircularProgressIndicator())
          : Container(
              height: double.infinity,
              child: WebViewWidget(
                url: _url,
                html: _html,
              ),
            ),
    );
  }
}
