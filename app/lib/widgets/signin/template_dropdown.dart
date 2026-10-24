import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:shared_preferences/shared_preferences.dart';

class TemplateDropdown extends StatefulWidget {
  final Function(String) onChange;
  const TemplateDropdown({this.onChange(index)});

  @override
  _TemplateDropdownState createState() => _TemplateDropdownState();
}

class _TemplateDropdownState extends State<TemplateDropdown> {
  List<String> _templateList = ['Default', 'C711', 'C712', 'C713'];
  String _currentTemplate = 'Default';

  @override
  void initState() {
    super.initState();
    _init();
  }

  void _init() async {
    final prefs = await SharedPreferences.getInstance();
    String templateId = prefs.getString('templateId') ?? _templateList[0];
    _changeTemplate(templateId);
  }

  void _changeTemplate(String template) {
    setState(() {
      _currentTemplate = template;
      saveTemplate(template);
      if (widget.onChange != null) widget.onChange(template);
    });
  }

  @override
  Widget build(BuildContext context) {
    return BaseCard(
      borderRadius: 30,
      height: 40,
      color: currentTheme.container,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.line_style,
            color: currentTheme.textHead,
          ),
          SizedBox(width: 5),
          DropdownButton<String>(
            onChanged: (template) {
              _changeTemplate(template);
            },
            hint: Text(
              _currentTemplate,
              style: TextStyle(
                color: currentTheme.textHead,
              ),
            ),
            underline: SizedBox(),
            icon: Icon(
              Icons.keyboard_arrow_down,
              color: currentTheme.textHead,
            ),
            dropdownColor: currentTheme.container,
            items: _templateList.map((template) {
              return DropdownMenuItem(
                  value: template,
                  child: Text(
                    template,
                    style: TextStyle(
                      color: currentTheme.textHead,
                    ),
                  ));
            }).toList(),
          ),
        ],
      ),
    );
  }
}
