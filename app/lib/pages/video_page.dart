import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/video.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';
import 'package:get/get.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';

import 'package:url_launcher/url_launcher.dart';

class VideoPage extends StatefulWidget {
  VideoPage();
  @override
  _VideoPageState createState() => _VideoPageState();
}

class _VideoPageState extends State<VideoPage> {
  bool _loading = true;
  List<Video> videos = [];
  @override
  void initState() {
    super.initState();
    fetchData();
  }

  Future<void> fetchData() async {
    setState(() {
      _loading = true;
    });
    videos = await fetchVideos();
    setState(() {
      _loading = false;
    });
  }

  void _openVideo(int index) async {
    Video video = videos[index];
    String downloadUrl = "";
    String openUrl = "";
    if (GetPlatform.isIOS) {
      downloadUrl = video.iosDownload;
      openUrl = video.iosOpen;
    } else {
      downloadUrl = video.androidDownload;
      openUrl = video.androidOpen;
    }
    showLoadingDialog();
    bool canOpenApp = await canLaunch(openUrl);
    pop();
    await launch(canOpenApp ? openUrl : downloadUrl);
  }

  Widget _buildVideo({
    @required int index,
    @required String name,
    @required String imagePath,
  }) {
    return Column(
      children: [
        GestureDetector(
          onTap: () => _openVideo(index),
          child: Image.asset(
            imagePath,
            height: 120,
          ),
        ),
        Text(
          name,
          style: TextStyle(fontSize: 18),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('downLoadAPP'.tr),
      child: _loading
          ? Center(child: CircularProgressIndicator())
          : Center(
              child: Column(
                children: [
                  SizedBox(height: 40),
                  Text(
                    'appInstallHint'.tr,
                    style: TextStyle(fontSize: 18, color: currentTheme.icon),
                    textAlign: TextAlign.center,
                  ),
                  SizedBox(height: 40),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      _buildVideo(
                        index: 1,
                        name: 'VMovie',
                        imagePath: 'assets/images/lobby/video2.png',
                      ),
                      SizedBox(width: 40),
                      _buildVideo(
                        index: 0,
                        name: 'dictok',
                        imagePath: 'assets/images/lobby/video1.png',
                      ),
                    ],
                  ),
                  SizedBox(height: 40),
                  _buildVideo(
                    index: 2,
                    name: 'vnewsdaily',
                    imagePath: 'assets/images/lobby/video3.png',
                  ),
                ],
              ),
            ),
    );
  }
}
