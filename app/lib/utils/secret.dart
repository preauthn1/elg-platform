import 'dart:convert';
import 'dart:typed_data';
import 'package:crypto/crypto.dart';
import 'package:encrypt/encrypt.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

String generateMd5(String value) {
  return md5.convert(utf8.encode(value)).toString();
}

Uint8List generateBytes(String value) {
  List<int> list = value.codeUnits;
  Uint8List bytes = Uint8List.fromList(list);
  return bytes;
}

String encrypt(String data) {
  var keyHash = generateMd5(currentEnv.apiAesKey);
  var key = utf8.encode(keyHash);
  var iv = utf8.encode(currentEnv.apiIv);
  var bytes = generateBytes(data);
  var encrypted = AES(Key(key), mode: AESMode.cbc).encrypt(bytes, iv: IV(iv));
  return encrypted.base64;
}

String decrypt(String data) {
  var keyHash = generateMd5(currentEnv.apiAesKey);
  var key = utf8.encode(keyHash);
  var iv = utf8.encode(currentEnv.apiIv);
  var decrypted = AES(Key(key), mode: AESMode.cbc)
      .decrypt(Encrypted.from64(data), iv: IV(iv));
  return String.fromCharCodes(decrypted);
}

String encryptChat(String data) {
  var keyHash = generateMd5(currentEnv.chatAesKey);
  var key = utf8.encode(keyHash);
  var iv = utf8.encode(currentEnv.chatIv);
  var bytes = generateBytes(data);
  var encrypted = AES(Key(key), mode: AESMode.cbc).encrypt(bytes, iv: IV(iv));
  return encrypted.base64;
}
