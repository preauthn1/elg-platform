import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';

class BaseTextField extends StatefulWidget {
  final TextEditingController controller;
  final bool obscureText;
  final EdgeInsetsGeometry margin;
  final Color color;
  final List<Color> colors;
  final String hintText;
  final TextAlign textAlign;
  final TextInputType keyboardType;
  final Function(String value) onChanged;
  final Widget prefix;
  final Widget suffix;
  BaseTextField({
    this.controller,
    this.margin = const EdgeInsets.only(top: 30),
    this.obscureText = false,
    this.color,
    this.colors,
    this.hintText,
    this.keyboardType,
    this.textAlign = TextAlign.right,
    this.prefix,
    this.suffix,
    this.onChanged,
  });

  @override
  _BaseTextFieldState createState() => _BaseTextFieldState();
}

class _BaseTextFieldState extends State<BaseTextField> {
  FocusNode _focus = FocusNode();
  bool _hasFocus = false;
  @override
  void initState() {
    super.initState();
    _focus.addListener(_onFocusChange);
  }

  void _onFocusChange() {
    setState(() {
      _hasFocus = _focus.hasFocus;
    });
  }

  @override
  Widget build(BuildContext context) {
    return BaseCard(
      borderColors: _hasFocus
          ? currentTheme.borderInputFocus
          : currentTheme.borderListButton,
      height: 40,
      color: widget.color != null ? widget.color : currentTheme.container,
      colors: widget.colors,
      margin: widget.margin,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            widget.prefix == null ? SizedBox() : widget.prefix,
            SizedBox(width: widget.prefix == null ? 0 : 8),
            Expanded(
              child: TextField(
                focusNode: _focus,
                textAlign: widget.textAlign,
                controller: widget.controller,
                obscureText: widget.obscureText,
                keyboardType: widget.keyboardType,
                decoration: InputDecoration.collapsed(
                  hintStyle: TextStyle(fontSize: 12),
                  hintText: widget.hintText,
                  fillColor: Colors.black,
                  border: InputBorder.none,
                ),
                textAlignVertical: TextAlignVertical.center,
                onChanged: widget.onChanged,
              ),
            ),
            SizedBox(width: widget.suffix == null ? 0 : 8),
            widget.suffix == null ? SizedBox() : widget.suffix,
          ],
        ),
      ),
    );
  }
}
