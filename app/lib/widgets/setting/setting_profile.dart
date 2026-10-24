import 'dart:io';
import 'dart:ui';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/pages/change_nickname_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_avator.dart';
import 'package:futter_web_frontend/widgets/base/base_circle_button.dart';
import 'package:get/get.dart';

class SettingProfile extends StatefulWidget {
  @override
  _SettingProfileState createState() => _SettingProfileState();
}

class _SettingProfileState extends State<SettingProfile> {
  bool _isLoading = false;
  MemberController _memberController = MemberController.to;
  @override
  void initState() {
    super.initState();

    if (_memberController.member.value.username == null) {
      _refreshMember();
    }
  }

  Future<void> _refreshMember() async {
    setState(() {
      _isLoading = true;
    });
    await _memberController.fetchAndSetMember();
    setState(() {
      _isLoading = false;
    });
  }

  Widget _buildImage() {
    String imageUrl = _memberController.member.value.image;
    if (imageUrl == null || imageUrl == 'null') {
      return Image.asset(
        'assets/images/lobby/user.jpg',
        fit: BoxFit.contain,
      );
    } else {
      return Image.network(
        imageUrl,
        fit: BoxFit.contain,
      );
    }
  }

  //選擇照片
  void _takePicture() async {
    showTakePictureButtonSheet(
      onSelect: (image) async {
        setState(() {
          _isLoading = true;
        });
        try {
          File file = File(image.path);
          String base64 = await fileToBase64(file);
          await updateImage(base64);
          await _refreshMember();
        } catch (error) {}
        setState(() {
          _isLoading = false;
        });
      },
    );
  }

  Widget _buildUserInfo({
    String title,
    Widget detail,
    Function onTap,
  }) {
    return InkWell(
      child: BaseCard(
        padding: EdgeInsets.all(10),
        margin: EdgeInsets.symmetric(horizontal: 20),
        height: 40,
        colors: currentTheme.buttonList,
        borderColors: currentTheme.borderListButton,
        insideBorderWidth: 0,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(title),
            detail,
          ],
        ),
      ),
      onTap: onTap,
    );
  }

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        BaseCard(
          height: 350,
          color: currentTheme.container,
          insideBorderWidth: 0,
          child: _isLoading
              ? Center(child: CircularProgressIndicator())
              : Column(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    Container(
                      height: 120,
                      width: 150,
                      child: Stack(
                        children: [
                          Center(
                            child: Container(
                              height: 120,
                              width: 120,
                              //頭像外框
                              decoration: BoxDecoration(
                                border: Border.all(
                                  color: currentTheme.border,
                                  width: 2,
                                ),
                                borderRadius:
                                    BorderRadius.all(Radius.circular(180)),
                              ),
                              //頭像
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(180),
                                child: Obx(() => _buildImage()),
                              ),
                            ),
                          ),
                          //上傳頭像
                          Align(
                            alignment: Alignment.bottomRight,
                            child: BaseCircleButton(
                              size: 40,
                              child: BaseCircleAvator(
                                size: 30,
                                color: currentTheme.container,
                                borderColor: currentTheme.border,
                                child: Icon(
                                  Icons.camera_alt,
                                  color: currentTheme.icon,
                                  size: 20,
                                ),
                              ),
                              onPressed: _takePicture,
                            ),
                          ),
                        ],
                      ),
                    ),
                    //用戶名
                    _buildUserInfo(
                      title: 'nickname'.tr,
                      detail: Row(
                        children: [
                          Obx(
                            () => Text(_memberController.member.value.nickname),
                          ),
                          SizedBox(width: 10),
                          Icon(
                            Icons.edit,
                            size: 20,
                            color: currentTheme.icon,
                          ),
                        ],
                      ),
                      onTap: () => push(ChangeNicknamePage()),
                    ),
                    //帳號
                    _buildUserInfo(
                      title: 'account'.tr,
                      detail: Row(
                        children: [
                          Obx(
                            () => Text(_memberController.member.value.username),
                          ),
                          SizedBox(width: 30),
                        ],
                      ),
                    ),
                    //電話
                    _buildUserInfo(
                      title: 'cellphoneNumber'.tr,
                      detail: Row(
                        children: [
                          Obx(
                            () => Text(_memberController.member.value.mobile),
                          ),
                          SizedBox(width: 30),
                        ],
                      ),
                    ),
                  ],
                ),
        ),
      ],
    );
  }
}
