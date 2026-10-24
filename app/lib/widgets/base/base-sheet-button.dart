import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';

class BaseSheetButton extends StatelessWidget {
  final Widget icon;
  final String text;
  final Function() onPressed;
  BaseSheetButton({
    this.icon,
    @required this.text,
    @required this.onPressed,
  });
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(8),
      child: BaseButton.widget(
        colors: currentTheme.buttonSecondary,
        borderColors: currentTheme.borderCard,
        width: double.infinity,
        insideBorderWidth: 0,
        child: Stack(
          alignment: Alignment.center,
          fit: StackFit.expand,
          children: [
            if (icon != null) Positioned(left: 0, child: icon),
            Center(
              child: Text(
                text,
                style: TextStyle(
                  color: currentTheme.textHead,
                  fontWeight: FontWeight.bold,
                ),
              ),
            ),
          ],
        ),
        onPressed: onPressed,
      ),
    );
  }
}
