import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/template_controller.dart';
import 'package:futter_web_frontend/models/flow_limit.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:get/utils.dart';

class FlowLimitText extends StatefulWidget {
  final List<FlowLimit> flowLimitList;

  const FlowLimitText({this.flowLimitList});

  @override
  _FlowLimitTextState createState() => _FlowLimitTextState();
}

class _FlowLimitTextState extends State<FlowLimitText> {
  List<FlowLimit> _flowLimitList;
  bool _loading = false;
  bool _flowLimitDisplayVisible = TemplateController
          .to.template.value.customizedSetting.flowLimitDisplayVisible ==
      '1';
  @override
  void initState() {
    super.initState();
    _fetchData();
  }

  Future<void> _fetchData() async {
    if (widget.flowLimitList == null) {
      setState(() {
        _loading = true;
      });
      _flowLimitList = await fetchFlowLimit();
      setState(() {
        _loading = false;
      });
    } else {
      _flowLimitList = widget.flowLimitList;
    }
  }

  String get _flowLimitText {
    String reach = numberFormatter(_flowLimitList[0].reach);
    String flowLimit = numberFormatter(_flowLimitList[0].flowLimit);
    return '${'current_flow'.tr}/${'flow_limit'.tr} : $reach/$flowLimit';
  }

  @override
  Widget build(BuildContext context) {
    if (_flowLimitDisplayVisible) {
      return Container(
        margin: EdgeInsets.symmetric(vertical: 10),
        child: _loading
            ? Center(child: CircularProgressIndicator())
            : Text(
                _flowLimitText,
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: currentTheme.textHead,
                  fontSize: 16,
                ),
              ),
      );
    } else {
      return SizedBox();
    }
  }
}
