class Auth {
  int id;
  String username;
  String email;
  String nickname;
  String balance;
  String image;
  String firstName;
  String lastName;
  String mobile;
  int eventMode;
  int isForceUnlockEventMode;
  String lastLogin;
  String token;
  String csApiUser;
  String csApiKey;
  String shareCode;

  Auth({
    this.id,
    this.username,
    this.nickname,
    this.balance,
    this.image,
    this.firstName,
    this.lastName,
    this.mobile,
    this.eventMode,
    this.isForceUnlockEventMode,
    this.lastLogin,
    this.token,
    this.csApiUser,
    this.csApiKey,
    this.shareCode,
  });
}
