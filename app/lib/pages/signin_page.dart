import 'dart:convert';
import 'package:connectivity/connectivity.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/http_exception.dart';
import 'package:futter_web_frontend/pages/reset_password_page.dart';
import 'package:futter_web_frontend/pages/tabs_page.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_text_field.dart';
import 'package:futter_web_frontend/widgets/signin/apienv_dropdown.dart';
import 'package:futter_web_frontend/widgets/signin/signin_bottom_button.dart';
import 'package:open_file/open_file.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:futter_web_frontend/pages/phone_verification_page.dart';
import 'package:futter_web_frontend/pages/signup_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/utils/secret.dart';
import 'package:futter_web_frontend/widgets/signin/no_internet_connection_widget.dart';
import 'package:futter_web_frontend/widgets/signin/language_dropdown.dart';
import 'package:futter_web_frontend/widgets/signin/template_dropdown.dart';
import 'package:get/get.dart';
import 'package:url_launcher/url_launcher.dart';

class SigninPage extends StatefulWidget {
  @override
  _SigninPageState createState() => _SigninPageState();
}

class _SigninPageState extends State<SigninPage> {
  final TextEditingController _usernameController = TextEditingController();
  final TextEditingController _passwordController = TextEditingController();
  bool _noInternetConnection = false;
  bool _loading = true;
  String _version = '0.0.0';
  String _newVersionDownloadLink;
  String _newVersionFilePath = '';
  double _downloadProgress = 0;
  @override
  void initState() {
    super.initState();
    init();
  }

  Future<void> init() async {
    setState(() {
      _loading = true;
    });
    try {
      _noInternetConnection =
          await getConnectivityResult() == ConnectivityResult.none;
      if (_noInternetConnection) {
        throw Exception('networkAnomaly'.tr);
      }
      await TemplateController.to.fetchAndSetTemplate();
      _version = await getVersion();
      await _confirmVersion();
      await _tryAutoInputData();
      await fetchAndSetLanguage();
    } catch (error) {
      showMessageDialog(message: error.toString());
    }
    setState(() {
      _loading = false;
    });
  }

  //檢查更新
  Future<void> _confirmVersion() async {
    if (!isRelease) {
      return;
    }
    try {
      _newVersionDownloadLink = await checkHaveNewVersionDownloadLink();
      if (_newVersionDownloadLink != null) {
        if (GetPlatform.isAndroid) _downloadApk();
      }
    } catch (error) {
      throw error;
    }
  }

  //切換環境
  void onChangeEnv(int index) async {
    setState(() {
      _loading = true;
    });
    try {
      await TemplateController.to.fetchAndSetTemplate();
    } catch (error) {
      print(error);
    }
    setState(() {
      _loading = false;
    });
  }

  //自動填入帳密
  Future<void> _tryAutoInputData() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.containsKey('userdata')) {
      try {
        final data = prefs.getString('userdata');
        final userdata = json.decode(decrypt(data));
        _usernameController.text = userdata['username'];
        _passwordController.text = userdata['password'];
      } catch (error) {
        print(error);
      }
    }
  }

  //登入
  Future<void> _login() async {
    try {
      showLoadingDialog();
      await AuthController.to.login(
        _usernameController.text.trim(),
        _passwordController.text,
      );
      Get.offAll(TabsPage());
    } on HttpException catch (error) {
      pop();
      if (error.code == '9007') {
        pushToPhoneVerification(1, error.data);
      }
    } catch (error) {
      pop();
    }
  }

  void pushToPhoneVerification(int type, String memberId) {
    push(
      PhoneVerificationPage(
        type,
        memberId,
        onSuccess: (token, username) {
          //手機驗證
          if (type == 1) {
            _login();
          }
          //忘記密碼 重設
          else if (type == 2) {
            pop();
            push(ResetPasswordPage(token, username));
          }
        },
      ),
    );
  }

  void _openNewVersionFile() async {
    if (GetPlatform.isAndroid) {
      OpenFile.open(_newVersionFilePath);
    } else if (GetPlatform.isIOS) {
      showLoadingDialog();
      if (await canLaunch(_newVersionDownloadLink)) {
        await launch(_newVersionDownloadLink);
        pop();
      } else {
        pop();
        showMessageDialog(message: 'anErrorOccurred'.tr);
      }
    }
  }

  //更新APP
  void _downloadApk() async {
    var dir = await getExternalStorageDirectory();
    Dio dio = Dio();
    _newVersionFilePath = '${dir.path}/app.apk';
    dio.download(
      _newVersionDownloadLink,
      _newVersionFilePath,
      onReceiveProgress: (count, total) async {
        setState(() {
          _downloadProgress = count / total;
        });
        if (_downloadProgress >= 1) _openNewVersionFile();
      },
    );
  }

  //頂部設置
  Widget _buildTopSetting() {
    return Container(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (!isRelease)
            Container(
              margin: EdgeInsets.symmetric(vertical: 5),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.end,
                children: [
                  TemplateDropdown(
                    onChange: (index) async {
                      setState(() {
                        _loading = true;
                      });
                      await refreshTemplate();
                      setState(() {
                        _loading = false;
                      });
                    },
                  ),
                  SizedBox(width: 8),
                  ApienvDropdown(
                    onChange: (index) {
                      onChangeEnv(index);
                    },
                  ),
                ],
              ),
            ),
          LanguageDropdown(),
          Expanded(child: Container()),
          _buildLogo(),
          Expanded(child: Container()),
        ],
      ),
    );
  }

  //Logo
  Widget _buildLogo() {
    String logoUrl = TemplateController.to.template.value.logoMobile;
    return Container(
      width: double.infinity,
      height: 80,
      padding: const EdgeInsets.symmetric(horizontal: 20),
      child: logoUrl != null
          ? Image.network(
              logoUrl,
              fit: BoxFit.scaleDown,
            )
          : null,
    );
  }

  //版號
  Widget _buildVersionText() {
    return Text(
      'V' + _version,
    );
  }

  //登入
  Widget _buildSignin() {
    return Column(
      children: [
        //帳號
        BaseTextField(
          margin: null,
          textAlign: TextAlign.left,
          colors: currentTheme.buttonList,
          controller: _usernameController,
          hintText: 'loginphone'.tr,
        ),
        SizedBox(height: 5),
        //密碼
        BaseTextField(
          margin: null,
          textAlign: TextAlign.left,
          colors: currentTheme.buttonList,
          controller: _passwordController,
          hintText: 'password'.tr,
          obscureText: true,
        ),
        SizedBox(height: 20),
        //登入
        BaseButton(
          width: double.infinity,
          colors: currentTheme.buttonDanger,
          text: 'signIn'.tr,
          onPressed: _login,
        ),
        SizedBox(height: 10),
        Row(
          children: <Widget>[
            //註冊
            Expanded(
              child: BaseButton(
                colors: currentTheme.buttonWarning,
                text: 'signUp'.tr,
                onPressed: () {
                  push(SignupPage());
                },
              ),
            ),
            SizedBox(width: 10),
            //忘記密碼
            Expanded(
              child: BaseButton(
                colors: currentTheme.buttonDisable,
                text: 'forget'.tr,
                onPressed: () {
                  pushToPhoneVerification(2, '');
                },
              ),
            ),
          ],
        ),
      ],
    );
  }

  //登入畫面
  Widget _buildSigninPage() {
    return Container(
      padding: EdgeInsets.symmetric(vertical: 10),
      height: Get.height - 20,
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          //語言、環境
          Expanded(flex: 4, child: _buildTopSetting()),
          //登入資訊
          Expanded(flex: 5, child: _buildSignin()),
          //聯繫客服
          Expanded(
            flex: 3,
            child: SignInBottomButton(),
          ),
        ],
      ),
    );
  }

  Widget _buildDownloadApkProgress() {
    return Column(
      children: [
        LinearProgressIndicator(
          value: _downloadProgress,
        ),
        SizedBox(height: 20),
        Text(
          '${(_downloadProgress * 100).floor()} %',
          textAlign: TextAlign.center,
        ),
      ],
    );
  }

  //更新畫面
  Widget _buildUpdatePage() {
    return Center(
      child: BaseCard(
        width: double.infinity,
        color: currentTheme.container,
        padding: EdgeInsets.all(30),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'downloadNewVersion'.tr,
              textAlign: TextAlign.center,
            ),
            SizedBox(height: 20),
            if (GetPlatform.isAndroid) _buildDownloadApkProgress(),
            if (_downloadProgress >= 1 || GetPlatform.isIOS)
              Container(
                margin: EdgeInsets.only(top: 20),
                child: BaseButton(
                  height: 30,
                  colors: currentTheme.buttonPrimary,
                  text: 'update'.tr,
                  onPressed: () {
                    _openNewVersionFile();
                  },
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildPage() {
    //無網路連接
    if (_noInternetConnection) {
      return NoInternetConnectionWidget(
        onRetry: () {
          setState(() {
            init();
          });
        },
      );
    } else if (_loading) {
      return Center(child: CircularProgressIndicator());
    } else {
      return Container(
        decoration: currentTheme.getBgDecoration(2),
        width: double.infinity,
        height: double.infinity,
        padding: EdgeInsets.symmetric(horizontal: 20),
        child: Stack(
          children: [
            //頁面
            _newVersionDownloadLink != null
                ? _buildUpdatePage() //可更新
                : _buildSigninPage(), //登入
            //版號
            Positioned(
              right: 5,
              bottom: 5,
              child: _buildVersionText(),
            ),
          ],
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Scaffold(
        resizeToAvoidBottomInset: false,
        body: _buildPage(),
      ),
    );
  }
}
