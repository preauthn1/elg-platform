import 'dart:io';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/controller/payment_type_controller.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/utils/app_transitions.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:get/get.dart';

import 'package:futter_web_frontend/utils/app_setting.dart' as config;
import 'package:futter_web_frontend/pages/signin_page.dart';

import 'controller/auth_controller.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

void main() {
  HttpOverrides.global = new MyHttpOverrides();
  if (GetPlatform.isWeb) {
    runApp(MyApp());
  } else {
    SystemChrome.setSystemUIOverlayStyle(SystemUiOverlayStyle.light);
    WidgetsFlutterBinding.ensureInitialized();
    SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]).then(
      (_) => runApp(MyApp()),
    );
  }
}

class MyApp extends StatefulWidget {
  // This widget is the root of your application.
  @override
  _MyAppState createState() => _MyAppState();
}

class _MyAppState extends State<MyApp> {
  final auth = Get.put(AuthController());
  final member = Get.put(MemberController());
  final template = Get.put(TemplateController());
  final paymentType = Get.put(PaymentTypeController());
  bool _initializing = true;

  @override
  void initState() {
    super.initState();
    init();
  }

  Future<void> init() async {
    try {
      await config.init();
    } catch (e) {
      showMessageDialog(message: e.toString());
    }

    //推播
    if (!GetPlatform.isWeb) {
      await Firebase.initializeApp();
      FirebaseMessaging messaging = FirebaseMessaging.instance;

      await messaging.requestPermission(
        alert: true,
        announcement: false,
        badge: true,
        provisional: false,
        sound: true,
      );
      FirebaseMessaging.onMessage.listen((RemoteMessage message) {
        print('Got a message whilst in the foreground!');
        print('Message data: ${message.data}');

        if (message.notification != null) {
          print(
              'Message also contained a notification: ${message.notification}');
        }
      });
    }

    setState(() {
      _initializing = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return GetMaterialApp(
      localizationsDelegates: [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      debugShowCheckedModeBanner: false,
      themeMode: ThemeMode.dark,
      title: 'V-World',
      translations: AppTransitions(),
      theme: config.currentTheme.theme,
      home: _initializing
          ? Center(child: CircularProgressIndicator())
          : SigninPage(),
    );
  }
}

class MyHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext context) {
    return super.createHttpClient(context)
      ..badCertificateCallback =
          (X509Certificate cert, String host, int port) => true;
  }
}
