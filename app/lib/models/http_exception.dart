class HttpException implements Exception {
  final String code;
  final String message;
  final String data;
  const HttpException({this.code, this.message, this.data});

  @override
  String toString() {
    return message;
  }
}
