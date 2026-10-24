import 'package:flutter/material.dart';
import 'package:futter_web_frontend/pages/record_red_envelope_page.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_list_icon_button.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class RecordRedEnvelopeMenuPage extends StatelessWidget {
  void pushToRedEnvelope(BuildContext context, int type) {
    push(RecordRedEnvelopePage(type));
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('redEnvelopeRecord'.tr),
      child: Column(
        children: [
          BaseListIconButton(
            text: 'receivedRedEnvelope'.tr,
            icon: ListIcon.image(
              AssetImage('assets/images/record/red_envelope_1.png'),
            ),
            onPressed: () {
              pushToRedEnvelope(context, 2);
            },
          ),
          BaseListIconButton(
            text: 'redEnvelope'.tr,
            icon: ListIcon.image(
              AssetImage('assets/images/record/red_envelope_2.png'),
            ),
            onPressed: () {
              pushToRedEnvelope(context, 1);
            },
          ),
          BaseListIconButton(
            text: 'redEnvelopeRefund'.tr,
            icon: ListIcon.image(
              AssetImage('assets/images/record/red_envelope_3.png'),
            ),
            onPressed: () {
              pushToRedEnvelope(context, 3);
            },
          ),
        ],
      ),
    );
  }
}
