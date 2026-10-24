import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:scan/scan.dart';

class QrcodeReader extends StatefulWidget {
  final orderTypeId;

  const QrcodeReader(this.orderTypeId);
  @override
  _QrcodeReaderState createState() => _QrcodeReaderState();
}

class _QrcodeReaderState extends State<QrcodeReader> {
  ScanController controller = ScanController();

  @override
  void initState() {
    super.initState();
  }

  //新增訂單
  Future<void> _createOrder(code) async {
    try {
      showLoadingDialog();
      await createOrder(
        widget.orderTypeId,
        qrcode: code,
      );
    } catch (error) {
      controller.resume();
      pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        SizedBox(height: 20),
        Container(
          width: 250,
          height: 250,
          child: ScanView(
            controller: controller,
            scanLineColor: Colors.green.shade400,
            onCapture: (data) {
              _createOrder(data);
            },
          ),
        ),
      ],
    );
  }
}
