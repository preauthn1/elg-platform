import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_list_button.dart';

// ignore: must_be_immutable
class BaseListIconButton extends StatelessWidget {
  Widget child;
  final ListIcon icon;
  final Function onPressed;

  BaseListIconButton({
    String text,
    this.icon,
    this.onPressed,
  }) {
    this.child = Text(
      text,
      style: TextStyle(
        color: currentTheme.textBody2Color,
      ),
    );
  }

  BaseListIconButton.widget({
    this.child,
    this.icon,
    this.onPressed,
  });

  @override
  Widget build(BuildContext context) {
    return BaseListButton(
      onPressed: onPressed,
      colors: currentTheme.buttonList,
      child: Row(
        children: [
          Expanded(
            flex: 2,
            child: icon,
          ),
          Expanded(
            flex: 15,
            child: child,
          ),
          Expanded(
            flex: 2,
            child: Icon(
              onPressed != null ? Icons.navigate_next : null,
              size: 20,
              color: currentTheme.textBody2Color,
            ),
          ),
        ],
      ),
    );
  }
}

// ignore: must_be_immutable
class ListIcon extends StatelessWidget {
  Widget icons;
  final double _size = 20;
  final Color _color = currentTheme.icon;
  ListIcon(this.icons);
  ListIcon.icon(IconData icon) {
    this.icons = Icon(
      icon,
      size: _size,
      color: _color,
    );
  }
  ListIcon.image(ImageProvider image) {
    this.icons = Image(
      image: image,
      height: _size,
      width: _size,
      color: _color,
    );
  }
  ListIcon.svg(String svgName) {
    this.icons = SvgPicture.asset(
      svgName,
      height: _size,
      width: _size,
      color: _color,
    );
  }

  @override
  Widget build(BuildContext context) {
    return icons;
  }
}
