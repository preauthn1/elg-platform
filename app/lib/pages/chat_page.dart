import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/widgets/chat/chat_description_button.dart';
import 'package:futter_web_frontend/widgets/web_view_widget.dart';

class ChatPage extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Container(
        height: double.infinity,
        decoration: currentTheme.getBgDecoration(0),
        //切換語言後重新載入
        child: FutureBuilder(
          future: Future.delayed(
            Duration(seconds: 0),
          ),
          builder: (context, snapshot) =>
              snapshot.connectionState == ConnectionState.waiting
                  ? Center(
                      child: CircularProgressIndicator(),
                    )
                  : Stack(
                      children: [
                        WebViewWidget(url: AuthController.to.chatUrl),
                        ChatDescriptionButton(), //說明
                      ],
                    ),
        ),
      ),
    );
  }
}

/*
class ChatPage extends StatefulWidget {
  List<String> tabbarTitle = ['朋友', '聊天'];
  @override
  _ChatPageState createState() => _ChatPageState();
}

class _ChatPageState extends State<ChatPage>
    with SingleTickerProviderStateMixin {
  TabController _tabController;
  int _currentPageIndex = 0;

  Widget _buildTabbarButton(int index, String title) {
    return ClipPath(
      clipper: TabbarButtonBG(),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: () {
            print('ontap');
            _tabController..animateTo(index);
            if (_currentPageIndex != index) {}
          },
          child: Container(
            width: 100,
            color: _currentPageIndex == index
                ? Color(0xFFC50236)
                : Colors.transparent,
            child: Center(
              child: Text(
                title,
                style: TextStyle(fontSize: 20),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildFriendListTitle(String title) {
    return Container(
      padding: EdgeInsets.only(left: 10),
      height: 30,
      child: Text(
        title,
        style: TextStyle(fontSize: 20),
      ),
    );
  }

  Widget _buildFriendListButton(String title) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () {
          print(title);
        },
        child: Container(
          height: 40,
          margin: EdgeInsets.all(10),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(180),
                child: Image.asset('assets/images/chat/user.png'),
              ),
              SizedBox(width: 20),
              Text(title),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildChatListButton(String title, String detail, String dateTime) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: () {
          _tabController.animateTo(1);
          print(title);
        },
        child: Container(
          height: 60,
          margin: EdgeInsets.all(10),
          child: Row(
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(180),
                child: Image.asset('assets/images/chat/user.png'),
              ),
              SizedBox(width: 20),
              Expanded(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          detail,
                          style: TextStyle(
                            color: Color(0xFF877071),
                          ),
                        ),
                        Text(dateTime),
                      ],
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> get _tabbarButtons {
    List<Widget> buttons = [];
    for (int i = 0; i < widget.tabbarTitle.length; i++) {
      buttons.add(_buildTabbarButton(i, widget.tabbarTitle[i]));
    }
    return buttons;
  }

  @override
  void initState() {
    super.initState();
    _tabController =
        TabController(length: widget.tabbarTitle.length, vsync: this);
    _tabController.addListener(() {
      setState(() {
        _currentPageIndex = _tabController.index;
      });
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: DefaultTabController(
        length: 2,
        child: Scaffold(
          body: Column(
            children: [
              Container(
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      Color(0xff270700),
                      Color(0xff46100b),
                      Color(0xff711b1a),
                    ],
                    begin: Alignment.topCenter,
                    end: Alignment.bottomCenter,
                  ),
                ),
                height: 50,
                child: Container(
                  padding: EdgeInsets.only(left: 80),
                  child: Row(
                    children: _tabbarButtons,
                  ),
                ),
              ),
              Expanded(
                child: Container(
                  decoration: currentTheme.getBgDecoration(0),
                  child: TabBarView(
                    controller: _tabController,
                    children: [
                      ListView(
                        padding: const EdgeInsets.all(8),
                        children: [
                          _buildFriendListTitle('客服'),
                          _buildFriendListButton('Customer Service'),
                          _buildFriendListButton('Agent Service'),
                          SizedBox(height: 20),
                          _buildFriendListTitle('朋友'),
                          _buildFriendListButton('VIP'),
                        ],
                      ),
                      ListView(
                        padding: const EdgeInsets.all(8),
                        children: [
                          _buildChatListButton(
                              'Agent Serivce', '收紅包訊息', '2020-05-08 17:12:29'),
                          _buildChatListButton(
                              'VIP', 'Hi', '2020-05-07 18:05:54'),
                          _buildChatListButton('Customer Serivice', 'dear',
                              '2020-03-08 17:18:17'),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class TabbarButtonBG extends CustomClipper<Path> {
  @override
  getClip(Size size) {
    double h = size.height;
    double w = size.width;

    double offset = w * 0.1;
    Path path = Path()
      ..moveTo(offset, 0)
      ..lineTo(0, h)
      ..lineTo(w - offset, h)
      ..lineTo(w, 0);
    return path;
  }

  @override
  bool shouldReclip(CustomClipper oldClipper) {
    return true;
  }
}
*/
