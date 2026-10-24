import 'package:futter_web_frontend/models/member.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:get/get.dart';

class MemberController extends GetxController {
  final member = Member().obs;
  static MemberController get to => Get.find();

  Future<void> fetchAndSetMember() async {
    try {
      final responseData = await post(
        path: 'api/member',
      );
      final memberData = responseData['data'];
      member.update((value) {
        value.id = memberData['id'].toString();
        value.parent = memberData['parent'].toString();
        value.username = memberData['username'].toString();
        value.balance = memberData['balance'].toString();
        value.image = memberData['image'].toString();
        value.email = memberData['email'].toString();
        value.nickname = memberData['nickname'].toString();
        value.firstName = memberData['first_name'].toString();
        value.lastName = memberData['last_name'].toString();
        value.birthday = memberData['birthday'].toString();
        value.mobile = memberData['mobile'].toString();
        value.timezoneId = memberData['timezone_id'].toString();
        value.countryId = memberData['country_id'].toString();
        value.zipCode = memberData['zip_code'].toString();
        value.district = memberData['district'].toString();
        value.city = memberData['city'].toString();
        value.address = memberData['address'].toString();
        value.status = memberData['status'].toString();
        value.isValidate = memberData['is_validate'].toString();
        value.mobileValidate = memberData['mobile_validate'].toString();
        value.emailValidate = memberData['email_validate'].toString();
        value.eventMode = memberData['event_mode'].toString();
        value.lastLogin = memberData['last_login'].toString();
      });
    } catch (error) {
      throw (error);
    }
  }

  void updateNickname(String name) {
    member.update((value) {
      value.nickname = name;
    });
  }

  void updateBalance(String balance) {
    member.update((value) {
      value.balance = balance;
    });
  }
}
