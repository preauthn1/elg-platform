import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/advertisements.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';

class EventAdvertisements extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return FutureBuilder(
      future: fetchAdvertisements('1'),
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.done) {
          if (snapshot.hasData) {
            List<Advertisements> data = snapshot.data as List<Advertisements>;
            return Column(
              children: data.map(
                (advertisements) {
                  return Padding(
                    padding: const EdgeInsets.all(8.0),
                    child: GestureDetector(
                      onTap: () async {
                        Vendor vendor =
                            await fetchVendor(advertisements.vendorId);
                        pushToVendorGamePage(vendor);
                      },
                      child: Image.network(advertisements.image),
                    ),
                  );
                },
              ).toList(),
            );
          } else {
            return SizedBox();
          }
        }
        return SizedBox();
      },
    );
  }
}
