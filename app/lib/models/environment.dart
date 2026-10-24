import 'package:flutter/cupertino.dart';
import 'package:futter_web_frontend/utils/http_service.dart';

class Environment {
  final int id;
  final int agentId;
  final String name;
  List<String> apiHostname;
  final String apiChatPath;
  String domain;
  //Key
  final String apiMd5Key;
  final String apiAesKey;
  final String apiIv;
  //login lobby聊天室用
  final String chatapiMd5Key;
  final String chatapiAesKey;
  final String chatapiIv;
  // 聊天室SIGN_DATA加密
  // final String chatMd5Key;
  final String chatAesKey;
  final String chatIv;

  Environment({
    @required this.id,
    @required this.agentId,
    @required this.name,
    @required this.domain,
    this.apiHostname = const [
      'https://example.invalid/',
      'https://example.invalid/',
      'https://example.invalid/',
      'https://example.invalid/',
      'https://example.invalid/',
    ],
    this.apiChatPath = 'https://example.invalid/',
    this.apiMd5Key = "&zSMvVTK4gbDvCcU",
    this.apiAesKey = "M53B?xKnTVtVnjM^",
    this.apiIv = "3579653693526433",
    this.chatapiMd5Key = "k^QtGkDg7FXJ#@QC",
    this.chatapiAesKey = "mu5*rwp5j%3U4kZG",
    this.chatapiIv = "9240533016891799",
    // this.chatMd5Key = "k^QtGkDg7FXJ#@QC",
    this.chatAesKey = "geSFWewRF15463U45aw",
    this.chatIv = "9549841321179900",
  });

  Future<void> updateAPI() async {
    try {
      List<String> apiList = await fetchAlternateDomain();
      this.apiHostname = apiList;
    } catch (e) {
      throw (e);
    }
  }

  Future<void> updateDomain() async {
    try {
      String domain = await fetchDomain();
      this.domain = domain;
    } catch (e) {
      throw (e);
    }
  }
}
