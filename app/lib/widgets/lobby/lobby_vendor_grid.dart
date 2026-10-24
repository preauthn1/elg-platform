import 'package:flutter/material.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/models/game_types.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/lobby/lobby_hot_image.dart';

import 'lobby_new_image.dart';

class LobbyVendorGrid extends StatelessWidget {
  final List<Vendor> vendors;
  final List<GameTypes> gameTypes;
  final String filterTypeId;

  LobbyVendorGrid(this.vendors, this.gameTypes, this.filterTypeId);

  bool _littleVendor() {
    return _getVendorItems().length <= 9;
  }

  Widget _buildVendorImage(String src) {
    return Container(
      padding: EdgeInsets.all(5),
      child: Image.network(
        src,
      ),
    );
  }

  Widget _buildVendorItem(Vendor vendor) {
    //維護中
    if (vendor.status == '3') {
      return Padding(
        padding: const EdgeInsets.all(2),
        child: Stack(
          alignment: Alignment.center,
          children: [
            //遊戲商灰階
            Image.asset(
              currentTheme.getTemplateImage('vendor_disable_bg.png'),
            ),
            ColorFiltered(
              colorFilter: greyscale,
              child: _buildVendorImage(vendor.image),
            ),
            //維護結束日期
            Positioned(
              left: 0,
              bottom: 0,
              child: Container(
                width: 94,
                height: 20,
                decoration: BoxDecoration(
                  image: DecorationImage(
                    image: AssetImage('assets/images/lobby/maintain.png'),
                  ),
                ),
                child: Padding(
                  padding: const EdgeInsets.only(left: 5, top: 7),
                  child: Text(
                    dateFormatter(vendor.maintenanceEndTime,
                            format: 'MM/dd HH:mm') +
                        '+08',
                    style: TextStyle(
                        fontSize: 9,
                        color: Colors.red,
                        fontWeight: FontWeight.bold),
                  ),
                ),
              ),
            ),
          ],
        ),
      );
    }
    //開啟
    return InkWell(
      onTap: () {
        pushToVendorGamePage(vendor);
      },
      child: Stack(
        children: [
          Padding(
            padding: EdgeInsets.all(2),
            child: Stack(
              alignment: Alignment.center,
              children: [
                Image.asset(
                  currentTheme.getTemplateImage('vendor_bg.png'),
                ),
                _buildVendorImage(vendor.image),
              ],
            ),
          ),
          if (vendor.isHot == '1') LobbyHotImage(),
          if (vendor.isNew == '1') LobbyNewImage(),
        ],
      ),
    );
  }

  List<Widget> _getVendorItems() {
    List<Widget> gameList = [];
    for (final vendor in vendors) {
      //status 1:開啟，2:coming soon，3:維護中，4:禁止遊玩
      if (vendor.status == '4' ||
          (vendor.status == '3' &&
              (vendor.maintenanceEndTime == null ||
                  vendor.maintenanceEndTime == 'null'))) continue;
      //沒有篩選就全顯示
      if (filterTypeId == null) {
        gameList.add(_buildVendorItem(vendor));
      } else {
        for (GameTypes gameTypes in gameTypes) {
          if (gameTypes.typeId == filterTypeId &&
              gameTypes.vendorId == vendor.id) {
            gameList.add(_buildVendorItem(vendor));
            break;
          }
        }
      }
    }
    return gameList;
  }

  @override
  Widget build(BuildContext context) {
    return SliverPadding(
      padding: const EdgeInsets.all(8),
      sliver: SliverGrid(
        gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: _littleVendor() ? 2 : 3,
          childAspectRatio: 3 / 2,
          crossAxisSpacing: _littleVendor() ? 20 : 3,
          mainAxisSpacing: _littleVendor() ? 20 : 3,
        ),
        delegate: SliverChildBuilderDelegate(
          (BuildContext context, int index) {
            return _getVendorItems()[index];
          },
          childCount: _getVendorItems().length,
        ),
      ),
    );
  }
}
