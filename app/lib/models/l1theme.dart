import 'package:flutter/material.dart';

class L1Theme {
  final String name;
  final List<double> colorStops;
  final List<Color> buttonPrimary;
  final List<Color> buttonSecondary;
  final List<Color> buttonDanger;
  final List<Color> buttonWarning;
  final List<Color> buttonInfo;
  final List<Color> buttonList;
  final List<Color> buttonDisable;
  final List<Color> borderCard;
  final List<Color> borderButton;
  final List<Color> borderListButton;
  final List<Color> borderInputFocus;
  final List<Color> eventProgressBg;
  final List<Color> eventProgress;
  final double buttonRadius;
  final Color border;
  final Color textAppbar;
  final Color textHead;
  final Color textMuted;
  final Color textLink;
  final Color textDanger;
  final Color textAppbarBalance;
  final List<Color> dialogTitleBg;
  final Color icon;
  final Color appbarIcon;
  final Color container;
  final Color containerInsideBorder;
  final double containerInsideBorderWidth;
  final Color gameItemBorder;
  final double lobbyImageMarginTop;
  final Decoration appbarBalanceDecoration;
  final ThemeData theme;

  L1Theme({
    @required this.name,
    @required this.buttonPrimary,
    @required this.buttonSecondary,
    @required this.buttonDanger,
    @required this.buttonWarning,
    @required this.buttonInfo,
    @required this.buttonList,
    @required this.textHead,
    @required this.textAppbar,
    @required this.textMuted,
    @required this.textLink,
    @required this.textDanger,
    @required this.textAppbarBalance,
    @required this.buttonDisable,
    @required this.borderCard,
    @required this.borderButton,
    @required this.borderListButton,
    @required this.borderInputFocus,
    @required this.buttonRadius,
    @required this.containerInsideBorderWidth,
    @required this.eventProgressBg,
    @required this.eventProgress,
    @required this.dialogTitleBg,
    @required this.colorStops,
    @required this.icon,
    @required this.appbarIcon,
    @required this.container,
    @required this.border,
    @required this.gameItemBorder,
    @required this.theme,
    this.lobbyImageMarginTop = 0,
    this.containerInsideBorder = Colors.black,
    this.appbarBalanceDecoration,
  });

  Color get textBody2Color {
    return theme.textTheme.bodyText2.color;
  }

  String getTemplateImage(String imageName) {
    String templateName = name.toLowerCase();
    return 'assets/images/$templateName/$imageName';
  }

  Widget getAppbarBg(int index) {
    double height = 60;
    switch (name) {
      case "C712":
        return Container(
          height: height,
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                Color(0xFF5c5c5c),
                Color(0xFF212121),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        );
      case "C713":
        return Container(
          height: height,
          color: Color(0xFF74d4e0),
        );
      default:
        return Image.asset(
          'assets/images/c711/appbar_background_$index.png',
          height: height,
          fit: BoxFit.fill,
        );
    }
  }

  Decoration getBgDecoration(int index) {
    switch (name) {
      case "C712":
        return BoxDecoration(
          image: DecorationImage(
            alignment: Alignment.topCenter,
            image: AssetImage('assets/images/c712/body_background.png'),
            colorFilter: new ColorFilter.mode(
              Colors.black.withOpacity(0.5),
              BlendMode.hardLight,
            ),
            fit: BoxFit.cover,
          ),
        );
        break;
      case "C713":
        return BoxDecoration(
          color: Color(0xfff4f5f9),
        );
        break;
      default:
        return BoxDecoration(
          image: DecorationImage(
            alignment: Alignment.topCenter,
            image: AssetImage('assets/images/c711/body_background_$index.png'),
            fit: BoxFit.cover,
          ),
        );
        break;
    }
  }

  Widget getBottomNavBg() {
    switch (name) {
      case "C712":
        return Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                Color(0xFF2a2934),
                Color(0xFF211d23),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        );
      case "C713":
        return Container(
          decoration: BoxDecoration(
            color: Color(0xFFFFFFFF),
            boxShadow: [
              BoxShadow(
                color: Color(0xFFE9E9E9),
                spreadRadius: 3,
                blurRadius: 8,
              ),
            ],
          ),
        );
      default:
        return Image.asset(
          'assets/images/tabs/bottom_nav_bar.png',
          width: double.infinity,
          fit: BoxFit.fitWidth,
        );
    }
  }
}
