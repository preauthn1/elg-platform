import 'package:flutter/material.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';

class UnlimitDataTable extends StatelessWidget {
  final double _cellHeight = 60;
  final double _cellSpace = 0.5;
  final int headerFlex;
  final int detailFlex;

  final List<String> header;
  final List<List<String>> detail;

  UnlimitDataTable({
    @required this.header,
    @required this.detail,
    this.headerFlex = 1,
    this.detailFlex = 2,
  });

  Widget _buildDivider({
    Color color,
    double height = 2,
  }) {
    return Container(
      height: height,
      color: color,
    );
  }

  Widget _buildDataColumn(List<String> textData) {
    return Column(
      children: [
        for (int i = 0; i < textData.length; i++)
          Container(
            height: _cellHeight,
            margin: EdgeInsets.only(bottom: _cellSpace),
            child: Center(
              child: Text(
                textData[i],
                textAlign: TextAlign.center,
              ),
            ),
          ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: currentTheme.buttonSecondary,
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
        ),
      ),
      child: Stack(
        children: [
          Column(
            children: [
              _buildDivider(color: currentTheme.border),
              _buildDivider(
                color: currentTheme.theme.brightness == Brightness.dark
                    ? Colors.black
                    : Colors.white,
              ),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Expanded(
                    flex: headerFlex,
                    child: Container(
                      color: Colors.black.withAlpha(30),
                      child: _buildDataColumn(header),
                    ),
                  ),
                  Expanded(
                    flex: detailFlex,
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: [
                          ...detail.map(
                            (textData) {
                              return Container(
                                width: 150,
                                child: _buildDataColumn(textData),
                              );
                            },
                          )
                        ],
                      ),
                    ),
                  ),
                ],
              ),
              _buildDivider(
                color: currentTheme.theme.brightness == Brightness.dark
                    ? Colors.black
                    : Colors.white,
              ),
              _buildDivider(color: currentTheme.border),
            ],
          ),
          Column(
            children: [
              SizedBox(height: 4),
              for (int i = 0; i < header.length - 1; i++)
                Container(
                  padding: EdgeInsets.only(top: _cellHeight),
                  child: _buildDivider(
                    color: currentTheme.border,
                    height: _cellSpace,
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}
