import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';

class UnlimitToggle extends StatefulWidget {
  final List<String> childrenText;
  final Function onPressed;
  //final String unselected = 'assets/images/vreward/toggle.png';
  //final String selected = 'assets/images/vreward/toggle_highlight.png';

  UnlimitToggle({this.childrenText, this.onPressed});
  @override
  _UnlimitToggleState createState() => _UnlimitToggleState();
}

class _UnlimitToggleState extends State<UnlimitToggle> {
  int _selectIndex = 0;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      margin: EdgeInsets.all(20),
      child: Wrap(
        runSpacing: 10,
        spacing: 10,
        alignment: WrapAlignment.start,
        direction: Axis.horizontal,
        children: [
          for (int i = 0; i < widget.childrenText.length; i++)
            BaseButton(
              colors: _selectIndex == i
                  ? currentTheme.buttonPrimary
                  : currentTheme.buttonDisable,
              text: widget.childrenText[i],
              onPressed: () {
                setState(() {
                  _selectIndex = i;
                });
                widget.onPressed(i);
              },
            ),
        ],
      ),
    );
  }
}
