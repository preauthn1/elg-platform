import 'dart:core';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter_svg/flutter_svg.dart';
import 'package:futter_web_frontend/models/lucky_draw_tickets.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/http_service.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:futter_web_frontend/widgets/base/appbar_title_text.dart';
import 'package:futter_web_frontend/widgets/base/base_button.dart';
import 'package:futter_web_frontend/widgets/base/base_card.dart';
import 'package:futter_web_frontend/widgets/base/base_scaffold.dart';

import 'package:get/get.dart';

class EventLuckyDrawTicketsPage extends StatefulWidget {
  final String issueId;
  final Function() onReceive;
  EventLuckyDrawTicketsPage(this.issueId, {this.onReceive});

  @override
  _EventLuckyDrawTicketsPageState createState() =>
      _EventLuckyDrawTicketsPageState();
}

class _EventLuckyDrawTicketsPageState extends State<EventLuckyDrawTicketsPage> {
  final _perPage = 10;
  int _currentPage = 1;
  int get _pageTotal => int.parse(_ticketsData.pagination.pages);

  LuckyDrawTickets _ticketsData;

  //領取獎勵
  void _receive(String ticketId) async {
    showLoadingDialog();
    try {
      await luckyDrawReceive(ticketId);
      pop();
      await showMessageDialog(
          message: '${'successfully'.tr}！\n${'Msg_AnyQuestionPleaseAskCS'.tr}');
      if (widget.onReceive != null) widget.onReceive();
      setState(() {});
    } catch (error) {
      pop();
    }
  }

  //換頁
  void _changePage(int pageIndex) {
    if (pageIndex == _currentPage || pageIndex < 1 || pageIndex > _pageTotal)
      return;
    setState(() {
      _currentPage = pageIndex;
    });
  }

  //獎品名稱
  String _getRewardName(Tickets ticket) {
    String rewardName = '';
    //1:獎金
    if (ticket.rewardType == '1') {
      rewardName = '${ticket.rewardAmount}\n${ticket.statusText}';
      //2:獎品
    } else if (ticket.rewardType == '2') {
      rewardName = ticket.rewardName;
    }
    return rewardName;
  }

  ///抽獎券
  Widget _buildTicket(int index, Tickets ticket) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          //index
          Text(
            index.toString(),
            style: TextStyle(
              color: currentTheme.textHead,
              fontWeight: FontWeight.bold,
            ),
          ),
          //Number
          Text(
            ticket.ticketNumber,
            style: TextStyle(
              color: currentTheme.textHead,
              fontWeight: FontWeight.bold,
            ),
          ),
          //領取
          Stack(
            clipBehavior: Clip.none,
            children: [
              Center(
                child: ticket.status == '2'
                    //未領取
                    ? BaseButton.widget(
                        width: 150,
                        colors: currentTheme.buttonWarning,
                        child: Text(
                          _getRewardName(ticket),
                          textAlign: TextAlign.center,
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                        onPressed: () => _receive(ticket.ticketId),
                      )
                    //已領取、沒中獎
                    : BaseButton.widget(
                        width: 150,
                        colors: currentTheme.buttonDisable,
                        child: Text(
                          ticket.statusText,
                          style: TextStyle(color: Colors.grey),
                        ),
                      ),
              ),
              if (int.parse(ticket.rewardLevel) > 0)
                Positioned(
                  top: -10,
                  left: -10,
                  child: Container(
                    height: 30,
                    child: SvgPicture.asset(
                        'assets/images/events/activity_icon_level_${ticket.rewardLevel}.svg'),
                  ),
                )
            ],
          ),
        ],
      ),
    );
  }

  ///頁數算法
  Map<String, int> pageNumberAlgorithm() {
    int linkCount;
    if (_currentPage < 100) {
      linkCount = 8;
    } else if (_currentPage < 1000) {
      linkCount = 6;
    } else {
      linkCount = 4;
    }
    var start = max(1, _currentPage - (linkCount / 2).ceil());
    var end = min(_pageTotal, start + linkCount);
    start = max(1, end - linkCount);
    return {'start': start, 'end': end};
  }

  //頁數Text
  Widget _buildPageText(String text, int changePageIndex) {
    String currentPageText = _currentPage.toString();
    Color color = text == currentPageText
        ? currentTheme.textHead
        : currentTheme.textMuted;
    return Flexible(
      child: GestureDetector(
        onTap: () => _changePage(changePageIndex),
        child: Container(
          height: 17,
          constraints: BoxConstraints(minWidth: 25),
          padding: EdgeInsets.symmetric(horizontal: 3),
          color: Colors.transparent,
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(
              text,
              textAlign: TextAlign.center,
              style: TextStyle(color: color),
            ),
          ),
        ),
      ),
    );
  }

  //頁數
  Widget _buildPageNumber() {
    var pageNumber = pageNumberAlgorithm();
    List<Widget> textWidget = [];
    for (int i = pageNumber['start']; i <= pageNumber['end']; i++) {
      String text = i.toString();
      textWidget.add(_buildPageText(text, i));
    }

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        _buildPageText('《', 1),
        _buildPageText('〈', _currentPage - 1),
        ...textWidget, //頁數
        _buildPageText('〉', _currentPage + 1),
        _buildPageText('》', _pageTotal),
      ],
    );
  }

  //抽獎券列表
  Widget _buildRaffleTicket() {
    return BaseCard(
      color: currentTheme.container,
      insideBorderWidth: 0,
      margin: EdgeInsets.all(20),
      padding: EdgeInsets.symmetric(horizontal: 20),
      width: double.infinity,
      child: _ticketsData.tickets.length == 0
          //沒有抽獎券
          ? Column(
              children: [
                SizedBox(height: 40),
                Transform.rotate(
                  angle: 270,
                  child: Image.asset(
                    currentTheme.getTemplateImage('activity_img_lottery.png'),
                    height: 50,
                  ),
                ),
                SizedBox(height: 30),
                Text(
                  'Msg_NotFound_Ticket'.tr,
                  style: TextStyle(
                    color: Colors.grey[700],
                    fontSize: 15,
                  ),
                ),
                SizedBox(height: 40),
              ],
            )
          //抽獎券列表
          : Column(
              children: [
                ..._ticketsData.tickets.asMap().entries.map((entry) {
                  var index = ((_currentPage - 1) * _perPage) + entry.key + 1;
                  var ticket = entry.value;
                  return _buildTicket(index, ticket);
                }),
                _buildPageNumber(),
                SizedBox(height: 10),
              ],
            ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return BaseScaffold(
      appbarBackgroundIndex: 1,
      bodyBackgroundIndex: 1,
      appbarTitle: AppbarTitleText('Events_LuckyDraw_Label_MyRaffleTicket'.tr),
      child: FutureBuilder(
        future: fetchLuckyDrawTickets(
            widget.issueId, _perPage.toString(), _currentPage.toString()),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          } else {
            _ticketsData = snapshot.data as LuckyDrawTickets;
            return SingleChildScrollView(
              child: Column(
                children: [
                  _buildRaffleTicket(),
                ],
              ),
            );
          }
        },
      ),
    );
  }
}
