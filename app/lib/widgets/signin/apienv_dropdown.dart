import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/environment.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/env_list.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';

class ApienvDropdown extends StatefulWidget {
  final Function(int) onChange;
  const ApienvDropdown({this.onChange(index)});

  @override
  _ApienvDropdownState createState() => _ApienvDropdownState();
}

class _ApienvDropdownState extends State<ApienvDropdown> {
  void _changeEnv(int index) async {
    showLoadingDialog();
    try {
      await changeEnv(index);
    } catch (e) {
      print(e.toString());
    }
    pop();
    setState(() {
      saveEnv(index);
      if (widget.onChange != null) widget.onChange(index);
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
            Icons.connected_tv,
            color: currentTheme.textHead,
          ),
          SizedBox(width: 5),
          DropdownButton<Environment>(
            onChanged: (env) {
              _changeEnv(env.id);
            },
            hint: Text(
              currentEnv.name,
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
            items: envList.map((env) {
              return DropdownMenuItem(
                  value: env,
                  child: Text(
                    env.name,
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
