import 'package:flutter/material.dart';
import 'package:get/get.dart';

class NoInternetConnectionWidget extends StatelessWidget {
  final Function onRetry;

  const NoInternetConnectionWidget({this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            'networkAnomaly'.tr,
            style: TextStyle(fontSize: 20),
          ),
          SizedBox(height: 10),
          ElevatedButton(
            onPressed: () {
              if (onRetry != null) onRetry();
            },
            child: Text('Retry'),
          )
        ],
      ),
    );
  }
}
