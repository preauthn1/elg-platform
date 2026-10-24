import 'package:flutter/cupertino.dart';

class AnnouncementMessage {
  final String announcementMessageId;
  final String title;
  final String message;
  final String createdAt;
  final String type;
  AnnouncementMessage({
    @required this.announcementMessageId,
    @required this.title,
    @required this.message,
    @required this.createdAt,
    @required this.type,
  });
}
