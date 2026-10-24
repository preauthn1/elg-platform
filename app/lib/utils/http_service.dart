import 'dart:convert';
import 'package:connectivity/connectivity.dart';
import 'package:flutter/cupertino.dart';
import 'package:futter_web_frontend/controller/auth_controller.dart';
import 'package:futter_web_frontend/controller/member_controller.dart';
import 'package:futter_web_frontend/models/advertisements.dart';
import 'package:futter_web_frontend/models/announcement_message.dart';
import 'package:futter_web_frontend/models/bank_account.dart';
import 'package:futter_web_frontend/models/bank_order.dart';
import 'package:futter_web_frontend/models/country.dart';
import 'package:futter_web_frontend/models/customer_service.dart';
import 'package:futter_web_frontend/models/deposit_order.dart';
import 'package:futter_web_frontend/models/event.dart';
import 'package:futter_web_frontend/models/flow_limit.dart';
import 'package:futter_web_frontend/models/game_types.dart';
import 'package:futter_web_frontend/models/http_exception.dart';
import 'package:futter_web_frontend/models/lucky_draw_issues.dart';
import 'package:futter_web_frontend/models/lucky_draw_issues_detail.dart';
import 'package:futter_web_frontend/models/lucky_draw_tickets.dart';
import 'package:futter_web_frontend/models/my_game.dart';
import 'package:futter_web_frontend/models/order.dart';
import 'package:futter_web_frontend/models/unlimit_vendor_rate.dart';
import 'package:futter_web_frontend/models/version.dart';
import 'package:futter_web_frontend/models/video.dart';
import 'package:futter_web_frontend/models/wallet_order_type.dart';
import 'package:futter_web_frontend/models/prize.dart';
import 'package:futter_web_frontend/models/red_envelope.dart';
import 'package:futter_web_frontend/models/transaction.dart';
import 'package:futter_web_frontend/models/unlimit_direct_players.dart';
import 'package:futter_web_frontend/models/unlimit_history.dart';
import 'package:futter_web_frontend/models/unlimit_reward.dart';
import 'package:futter_web_frontend/models/unlimit_withdraw_list.dart';
import 'package:futter_web_frontend/models/unlimit_withdraw_recode.dart';
import 'package:futter_web_frontend/models/vendor.dart';
import 'package:futter_web_frontend/models/unlimit.dart';
import 'package:futter_web_frontend/models/withdrawal_bank.dart';
import 'package:futter_web_frontend/models/withdrawal_info.dart';
import 'package:futter_web_frontend/models/withdrawal_record.dart';
import 'package:futter_web_frontend/utils/app_setting.dart';
import 'package:futter_web_frontend/utils/utils.dart';
import 'package:get/get.dart';
import 'package:http/http.dart' as http;
import 'app_setting.dart';
import 'secret.dart';

Future<Map<String, dynamic>> post({
  @required String path,
  Map<String, dynamic> parameters,
  Map<String, String> headers,
  int apiIndex = 0,
}) async {
  final authController = AuthController.to;
  // print('api index: $apiIndex');
  try {
    //檢查網路
    if (await getConnectivityResult() == ConnectivityResult.none) {
      throw HttpException(message: 'networkAnomaly'.tr);
    }
    final url = currentEnv.apiHostname[apiIndex] + path;
    Uri uri = Uri.parse(url);
    final token = [REDACTED];
    if (headers == null) {
      headers = {
        'content-type': 'application/json',
        'Accept-Language': currentLanguage['languageCode'],
      };
      if (token != null) {
        headers.addAll({
          'Authorization': "Bearer " + token,
        });
      }
    }
    final jsonMsg = json.encode(parameters);
    final params = encrypt(jsonMsg);
    final key = generateMd5(params + currentEnv.apiMd5Key);
    final body = json.encode({
      "params": params,
      'key': key,
    });
    final response = await http
        .post(uri, headers: headers, body: body)
        .timeout(Duration(seconds: 10), onTimeout: () {
      throw Exception('timeout');
    });
    //錯誤
    if (response.statusCode >= 400) {
      throw Exception('error code:${response.statusCode}');
    }
    final responseData = json.decode(response.body) as Map<String, dynamic>;
    print(responseData);

    if (responseData['code'] != 1000) {
      final errorCode = responseData['code'].toString();
      final errorData = responseData['data'].toString();
      final errorMsg = responseData['message'];

      if (errorMsg is List) {
        throw HttpException(
            code: errorCode, message: errorMsg.join('\n'), data: errorData);
      }
      if (errorMsg is Map) {
        if (errorMsg['desc'] != null)
          throw HttpException(
              code: errorCode, message: errorMsg['desc'], data: errorData);
        if (errorMsg['msg'] != null)
          throw HttpException(
              code: errorCode, message: errorMsg['msg'], data: errorData);
      } else {
        throw HttpException(
            code: errorCode, message: errorMsg, data: errorData);
      }
    }
    return responseData;
  } on HttpException catch (error) {
    await showMessageDialog(message: error.toString());
    if (error.code == '999') {
      String balance = await fetchTransferFromLastLog();
      MemberController.to.updateBalance(balance);
      popToFirst();
    }
    //token error
    if (error.code == '9998') {
      authController.clear();
      popToFirst();
    }
    throw error;
  } catch (error) {
    print(error);
    if (apiIndex + 1 < currentEnv.apiHostname.length) {
      return post(
        path: path,
        headers: headers,
        parameters: parameters,
        apiIndex: apiIndex + 1,
      );
    } else {
      await showMessageDialog(message: 'anErrorOccurred'.tr);
      throw error;
    }
  }
}

///版號
Future<VersionInfo> fetchVersion() async {
  try {
    final responseData = await post(
      path: 'api/versions',
      parameters: {
        'domain': currentEnv.domain,
      },
    );
    var versionData = responseData['data'];
    var iosData = versionData['ios'];
    var androidData = versionData['android'];
    return VersionInfo(
      iosVersion: iosData['version'].toString(),
      iosDownloadLink: iosData['downloadLink'].toString(),
      androidVersion: androidData['version'].toString(),
      androidDownloadLink: androidData['downloadLink'].toString(),
    );
  } catch (error) {
    throw (error);
  }
}

///Domain
Future<String> fetchDomain() async {
  try {
    final responseData = await post(
      path: 'api/frontend_domain',
      parameters: {
        'agent_id': currentEnv.agentId,
      },
    );
    return responseData['data'].toString();
  } catch (error) {
    throw (error);
  }
}

///備用網域API URL
Future<List<String>> fetchAlternateDomain() async {
  try {
    final responseData = await post(
      path: 'api/alternate_domain',
      parameters: {
        'domain': currentEnv.domain,
      },
    );
    var apiData = responseData['data'] as List<dynamic>;
    List<String> apiList = [];
    apiData.map((url) {
      apiList.add(url.toString());
    }).toList();
    return apiList;
  } catch (error) {
    throw (error);
  }
}

//*****************************************Vendor

///取得遊戲商明細
Future<Vendor> fetchVendor(String vendorId) async {
  try {
    final responseData = await post(
      path: 'api/vendor',
      parameters: {"vendor_id": vendorId},
    );
    var vendorData = responseData['data'];
    var vendor = Vendor(
      id: vendorData['id'].toString(),
      name: vendorData['name'].toString(),
      rate: vendorData['rate'].toString(),
      image: vendorData['image'].toString(),
      appIcon: vendorData['app_icon'].toString(),
      isNew: vendorData['is_new'].toString(),
      isHot: vendorData['is_hot'].toString(),
      needLogin: vendorData['need_login'].toString(),
      onlyOneGame: vendorData['only_one_game'].toString(),
      isSlow: vendorData['is_slow'].toString(),
      status: vendorData['status'].toString(),
      maintenanceEndTime: vendorData['maintenance_end_time'].toString(),
    );
    return vendor;
  } catch (error) {
    throw (error);
  }
}

//取遊戲商登入資訊
Future<Map<String, dynamic>> fetchVendorInfo(String vendorId) async {
  try {
    final responseData = await post(
      path: 'api/vendorInfo',
      parameters: {
        'vendor_id': vendorId,
        'domain': currentEnv.domain,
        'is_app': 1,
      },
    );
    return responseData['data'];
  } catch (error) {
    throw (error);
  }
}

///遊戲商列表
Future<List<Vendor>> fetchVendors() async {
  List<Vendor> vendors = [];
  try {
    final responseData = await post(
      path: 'api/vendors',
      parameters: {
        'type': 1,
        'is_app': 1,
      },
    );
    var vendorData = responseData['data'] as List<dynamic>;
    if (vendorData.length > 0) {
      vendorData.map(
        (vendor) {
          vendors.add(
            Vendor(
              id: vendor['id'].toString(),
              name: vendor['name'].toString(),
              rate: vendor['rate'].toString(),
              image: vendor['image'].toString(),
              appIcon: vendor['app_icon'].toString(),
              isNew: vendor['is_new'].toString(),
              isHot: vendor['is_hot'].toString(),
              needLogin: vendor['need_login'].toString(),
              onlyOneGame: vendor['only_one_game'].toString(),
              isSlow: vendor['is_slow'].toString(),
              status: vendor['status'].toString(),
              maintenanceEndTime: vendor['maintenance_end_time'].toString(),
            ),
          );
        },
      ).toList();
    }
    return vendors;
  } catch (error) {
    throw (error);
  }
}

///遊戲商類型列表
Future<List<GameTypes>> fetchVendorGameTypes() async {
  List<GameTypes> _gameTypes = [];
  try {
    final responseData = await post(path: 'api/vendor/gameTypes');
    var gameTypesData =
        responseData['data']['queryEachVendorGameGroup'] as List<dynamic>;
    if (gameTypesData.length > 0) {
      gameTypesData.map(
        (gameTypes) {
          _gameTypes.add(
            GameTypes(
              vendorId: gameTypes['vendor_id'].toString(),
              typeId: gameTypes['type_id'].toString(),
              rootType: gameTypes['root_type'].toString(),
            ),
          );
        },
      ).toList();
    }
    return _gameTypes;
  } catch (error) {
    throw (error);
  }
}

///重設遊戲商密碼
Future<void> resetVendorPassword(String vendorId, String newPassword) async {
  try {
    await post(
      path: 'api/resetVendorPassword',
      parameters: {
        "vendor_id": vendorId,
        "new_password": newPassword,
      },
    );
  } catch (error) {
    throw (error);
  }
}

///單一錢包餘額
Future<String> fetchSingleWalletBalance(String vendorId) async {
  try {
    final responseData = await post(
      //path: 'api/getVendorBalance',
      path: 'api/singleWalletBalance',
      parameters: {
        "vendor_id": vendorId,
      },
    );
    await MemberController.to.fetchAndSetMember();
    return responseData['data'].toString();
  } catch (error) {
    throw (error);
  }
}

// 2020-08-19 新開發，效能較好的 遊戲錢包轉中心錢包
Future<String> fetchTransferFromLastLog() async {
  try {
    final responseData = await post(path: 'api/transferFromLastLog');
    return responseData['data'].toString();
  } catch (error) {
    throw (error);
  }
}

// 影城
Future<List<Video>> fetchVideos() async {
  try {
    final responseData = await post(path: 'api/videos');
    final videosData = responseData['data'] as List<dynamic>;
    List<Video> videos = [];
    videosData
        .map(
          (video) => {
            videos.add(
              Video(
                androidDownload: video['android_download'].toString(),
                androidOpen: video['android_open'].toString(),
                appIcon: video['app_icon'].toString(),
                iosDownload: video['ios_download'].toString(),
                iosOpen: video['ios_open'].toString(),
                password: [REDACTED]'password'].toString(),
                username: video['username'].toString(),
              ),
            ),
          },
        )
        .toList();
    return videos;
  } catch (error) {
    throw (error);
  }
}

//**************************************MyGame */

///遊戲列表
Future<List<MyGame>> fetchGames(String vendorId) async {
  List<MyGame> games = [];
  try {
    final responseData = await post(
      path: 'api/myGames',
      parameters: {
        "vendor_id": vendorId,
        "page": 1,
        "per_page": 3000,
      },
    );
    var myGameData = responseData['data'] as List<dynamic>;
    if (myGameData.length > 0) {
      myGameData.map(
        (game) {
          games.add(
            MyGame(
              id: game['id'].toString(),
              vendorId: game['vendor_id'].toString(),
              vendorName: game['vendor_name'].toString(),
              typeId: game['type_id'].toString(),
              name: game['name'].toString(),
              lotteryPool: game['lottery_pool'].toString(),
              image: game['image'].toString(),
              isNew: game['is_new'].toString(),
              isHot: game['is_hot'].toString(),
              isFavourite: game['is_favourite'].toString(),
              eventBet: game['event_bet'].toString(),
              eventWin: game['event_win'].toString(),
              disable: game['disable'].toString(),
              redirectUrl: responseData['redirect_url'].toString(),
            ),
          );
        },
      ).toList();
    }
    return games;
  } catch (error) {
    throw (error);
  }
}

///遊戲列表
Future<MyGame> fetchGame(String gameId) async {
  try {
    final responseData = await post(
      path: 'api/game',
      parameters: {
        "game_id": gameId,
      },
    );
    var gameData = responseData['data'];
    MyGame game = MyGame(
      id: gameData['id'].toString(),
      vendorId: gameData['vendor_id'].toString(),
      vendorName: gameData['vendor_name'].toString(),
      typeId: gameData['type_id'].toString(),
      name: gameData['name'].toString(),
      lotteryPool: gameData['lottery_pool'].toString(),
      image: gameData['image'].toString(),
      isNew: gameData['is_new'].toString(),
      isHot: gameData['is_hot'].toString(),
      isFavourite: gameData['is_favourite'].toString(),
      eventBet: gameData['event_bet'].toString(),
      eventWin: gameData['event_win'].toString(),
      disable: gameData['disable'].toString(),
      redirectUrl: responseData['redirect_url'].toString(),
    );
    return game;
  } catch (error) {
    throw (error);
  }
}

//遊戲Url
Future<String> fetchGameUrl(String gameId) async {
  try {
    final responseData = await post(
      path: 'api/getGameUrl',
      parameters: {
        "game_id": gameId,
        "is_app": 1,
      },
    );
    return responseData['data'];
  } catch (error) {
    throw (error);
  }
}

//****************************************Events

///獲取活動列表
Future<List<Event>> fetchEvents() async {
  List<Event> events = [];
  try {
    final responseData = await post(path: 'api/events', parameters: {
      'page': 1,
      'per_page': 30,
    });
    var eventData = responseData['data'] as List<dynamic>;
    if (eventData.length > 0) {
      eventData.map(
        (event) {
          //Event detail
          var datailData = event['detail'] as List<dynamic>;
          List<EventDetail> details = [];
          datailData.map((detail) {
            details.add(
              EventDetail(
                id: detail['id'].toString(),
                actionId: detail['action_id'].toString(),
                orderTypeId: detail['order_type_id'].toString(),
                orderTypeName: detail['order_type_name'].toString(),
                gameTypeId: detail['game_type_id'].toString(),
                gameTypeName: detail['game_type_name'].toString(),
                vendorId: detail['vendor_id'].toString(),
                vendorName: detail['vendor_name'].toString(),
                games: null,
                gameId: detail['game_id'].toString(),
                rate: detail['rate'].toString(),
                point: detail['point'].toString(),
                startTime: detail['start_time'].toString(),
                endTime: detail['end_time'].toString(),
                nowPoint: detail['now_point'].toString(),
                actionName: detail['action_name'].toString(),
              ),
            );
          }).toList();

          //Event
          events.add(
            Event(
              id: event['id'].toString(),
              title: event['title'].toString(),
              content: event['content'].toString(),
              typeId: event['type_id'].toString(),
              agentId: event['agent_id'].toString(),
              prize: null,
              prizeId: event['prize_id'].toString(),
              prizeNumber: event['prize_number'].toString(),
              prizeIsRepeatedly: event['prize_is_repeatedly'].toString(),
              prizeExpireDate: event['prize_expire_date'].toString(),
              expireDate: event['expire_date'].toString(),
              prizeDrawDate: event['prize_draw_date'].toString(),
              count: event['count'].toString(),
              type: event['type'].toString(),
              showExchange: event['show_exchange'].toString(),
              showExchangeText: event['show_exchange_text'].toString(),
              remainingNumber: event['remaining_number'].toString(),
              startTime: event['start_time'].toString(),
              endTime: event['end_time'].toString(),
              detail: details,
            ),
          );
        },
      ).toList();
    }
    return events;
  } catch (error) {
    throw (error);
  }
}

///獲取活動詳情
Future<Event> fetchEvent(String id) async {
  try {
    final responseData = await post(path: 'api/event', parameters: {
      'id': id,
    });
    var eventData = responseData['data'] as List<dynamic>;
    var event = eventData[0];

    //Event detail
    var datailData = event['detail'] as List<dynamic>;
    List<EventDetail> details = [];
    datailData.map((detail) {
      //Event Game
      var gamesData = detail['games'] as List<dynamic>;
      List<EventGame> games = [];
      if (gamesData != null) {
        gamesData
            .map(
              (game) => games.add(
                EventGame(
                  id: game['id'].toString(),
                  image: game['image'].toString(),
                  isFavourite: game['is_favourite'].toString(),
                  isHot: game['is_hot'].toString(),
                  isNew: game['is_new'].toString(),
                  lotteryPool: game['lottery_pool'].toString(),
                  name: game['name'].toString(),
                  typeId: game['type_id'].toString(),
                  typeName: game['type_name'].toString(),
                  vendorId: game['vendor_id'].toString(),
                  vendorName: game['vendor_name'].toString(),
                ),
              ),
            )
            .toList();
      }

      details.add(
        EventDetail(
          id: detail['id'].toString(),
          actionId: detail['action_id'].toString(),
          orderTypeId: detail['order_type_id'].toString(),
          orderTypeName: detail['order_type_name'].toString(),
          gameTypeId: detail['game_type_id'].toString(),
          gameTypeName: detail['game_type_name'].toString(),
          vendorId: detail['vendor_id'].toString(),
          vendorName: detail['vendor_name'].toString(),
          rate: detail['rate'].toString(),
          point: detail['point'].toString(),
          startTime: detail['start_time'].toString(),
          endTime: detail['end_time'].toString(),
          nowPoint: detail['now_point'].toString(),
          actionName: detail['action_name'].toString(),
          gameId: detail['game_id'].toString(),
          games: games,
        ),
      );
    }).toList();

    //Event prize
    var prizeData = event['prize'] as List<dynamic>;
    List<EventPrize> prizes = [];
    prizeData.map((prize) {
      prizes.add(
        EventPrize(
          id: prize['id'].toString(),
          name: prize['name'].toString(),
          number: prize['number'].toString(),
          point: prize['point'].toString(),
        ),
      );
    }).toList();

    //Event
    event = Event(
      id: event['id'].toString(),
      title: event['title'].toString(),
      content: event['content'].toString(),
      typeId: event['type_id'].toString(),
      agentId: event['agent_id'].toString(),
      prizeId: event['prize_id'].toString(),
      prizeNumber: event['prize_number'].toString(),
      prizeIsRepeatedly: event['prize_is_repeatedly'].toString(),
      prizeExpireDate: event['prize_expire_date'].toString(),
      expireDate: event['expire_date'].toString(),
      prizeDrawDate: event['prize_draw_date'].toString(),
      count: event['count'].toString(),
      type: event['type'].toString(),
      showExchange: event['show_exchange'].toString(),
      showExchangeText: event['show_exchange_text'].toString(),
      remainingNumber: event['remaining_number'].toString(),
      startTime: event['start_time'].toString(),
      endTime: event['end_time'].toString(),
      detail: details,
      prize: prizes,
    );
    return event;
  } catch (error) {
    throw error;
  }
}

///取得中獎項目
Future<Map<String, dynamic>> fetchEventPrize(String eventId) async {
  try {
    final responseData = await post(
      path: 'api/getPrize',
      parameters: {
        'event_id': eventId,
      },
    );
    return responseData['data'];
  } catch (error) {
    throw error;
  }
}

///廣告列表
Future<List<Advertisements>> fetchAdvertisements(String pathId) async {
  List<Advertisements> advertisements = [];
  try {
    final responseData = await post(
      path: 'api/advertisements',
      parameters: {
        'path_id': pathId,
      },
    );
    final advertisementsData = responseData['data'] as List<dynamic>;

    advertisementsData
        .map(
          (data) => advertisements.add(
            Advertisements(
              image: data['image'].toString(),
              imagePc: data['image_pc'].toString(),
              url: data['url'].toString(),
              gameId: data['game_id'].toString(),
              gameTypeId: data['game_type_id'].toString(),
              vendorId: data['vendor_id'].toString(),
              eventId: data['event_id'].toString(),
            ),
          ),
        )
        .toList();

    return advertisements;
  } catch (error) {
    throw error;
  }
}

///期號列表
Future<List<LuckyDrawIssues>> fetchLuckyDrawIssues() async {
  List<LuckyDrawIssues> issues = [];
  try {
    final responseData = await post(
      path: 'api/event/lucky-draw/issues',
      parameters: {'domain': 'xxxxxxxx'},
    );
    final issuesData = responseData['data'] as List<dynamic>;
    issuesData
        .map(
          (data) => issues.add(
            LuckyDrawIssues(
              issueId: data['issue_id'].toString(),
              issueName: data['issue_name'].toString(),
              drawTime: data['draw_time'].toString(),
            ),
          ),
        )
        .toList();
    return issues;
  } catch (error) {
    throw error;
  }
}

/// 期號詳細資訊
Future<LuckyDrawIssuesDetail> fetchLuckyDrawIssueDetail(String issueId) async {
  try {
    final responseData = await post(
      path: 'api/event/lucky-draw/issues/detail',
      parameters: {'issue_id': issueId},
    );
    final detailData = responseData['data'];
    final summaryData = detailData['summary'];

    return LuckyDrawIssuesDetail(
      beginTime: detailData['begin_time'].toString(),
      endTime: detailData['end_time'].toString(),
      issueId: detailData['issue_id'].toString(),
      issueName: detailData['issue_name'].toString(),
      resultNumber: detailData['result_number'].toString(),
      summary: LuckyDrawIssuesDetailSummary(
        totalCountTickets: summaryData['totalCount_tickets'].toString(),
        totalCountUnclaimed: summaryData['totalCount_unclaimed'].toString(),
        totalCountWinnings: summaryData['totalCount_winnings'].toString(),
      ),
    );
  } catch (error) {
    throw error;
  }
}

/// 取得抽獎券 彩券列表
Future<LuckyDrawTickets> fetchLuckyDrawTickets(
    String issueId, String perPage, String page) async {
  try {
    final responseData = await post(
      path: 'api/event/lucky-draw/issues/tickets',
      parameters: {
        'issue_id': issueId,
        'per_page': perPage,
        'page': page,
      },
    );
    final resultData = responseData['data'];
    final paginationData = responseData['pagination'];
    final ticketsData = resultData['tickets'] as List<dynamic>;

    final resultNumber = resultData['result_number'].toString();
    final ticketsList = ticketsData
        .map(
          (tickets) => Tickets(
            ticketId: tickets['ticket_id'].toString(),
            ticketNumber: tickets['ticket_number'].toString(),
            rewardLevel: tickets['reward_level'].toString(),
            rewardAmount: tickets['reward_amount'].toString(),
            rewardName: tickets['reward_name'].toString(),
            rewardType: tickets['reward_type'].toString(),
            status: tickets['status'].toString(),
            statusText: tickets['statusText'].toString(),
          ),
        )
        .toList();
    final pagination = Pagination(
      current: paginationData['current'].toString(),
      next: paginationData['next'].toString(),
      pages: paginationData['pages'].toString(),
      perPage: paginationData['per_page'].toString(),
      previous: paginationData['previous'].toString(),
      total: paginationData['total'].toString(),
    );

    return LuckyDrawTickets(
      resultNumber: resultNumber,
      tickets: ticketsList,
      pagination: pagination,
    );
  } catch (error) {
    throw error;
  }
}

/// 領取獎勵
Future<void> luckyDrawReceive(String ticketId) async {
  try {
    await post(
      path: 'api/event/lucky-draw/issues/receive',
      parameters: {
        'ticket_id': ticketId,
      },
    );
  } catch (error) {
    throw error;
  }
}

/// 領取全部獎勵
Future<void> luckyDrawReceiveAll(String issueId) async {
  try {
    await post(
      path: 'api/event/lucky-draw/issues/receive-all',
      parameters: {
        'issue_id': issueId,
      },
    );
  } catch (error) {
    throw error;
  }
}

//*****************************************Unlimit

///無限代理
Future<Unlimit> fetchUnlimit() async {
  try {
    final responseData = await post(
      path: 'api/unlimit',
      parameters: {"domain": currentEnv.domain},
    );
    final data = responseData['data'];
    Unlimit unlimit = Unlimit(
      topUsername: data['topUsername'].toString(),
      username: data['username'].toString(),
      teamCount: data['teamCount'].toString(),
      directPlayerCount: data['directPlayerCount'].toString(),
      addedToday: data['addedToday'].toString(),
      activeToday: data['activeToday'].toString(),
      code: data['code'].toString(),
      link: data['link'].toString(),
    );
    return unlimit;
  } catch (error) {
    throw error;
  }
}

///提現總覽
Future<UnlimitWithdrawRecode> fetchWithdrawRecode() async {
  try {
    final responseData = await post(
      path: 'api/unlimit/withdrawRecode',
    );
    var data = responseData['data'];
    return UnlimitWithdrawRecode(
      username: data['username'].toString(),
      totalRewardPoints: data['totalRewardPoints'].toString(),
      withdrawablePoint: data['withdrawnPoints'].toString(),
      withdrawnPoints: data['withdrawablePoints'].toString(),
    );
  } catch (error) {
    throw error;
  }
}

///提現歷史紀錄
Future<List<UnlimitHistory>> fetchHistory() async {
  List<UnlimitHistory> _history = [];
  try {
    final responseData = await post(
      path: 'api/unlimit/history',
    );
    var historyData = responseData['data'] as List<dynamic>;
    if (historyData.length > 0) {
      for (var data in historyData) {
        _history.add(
          UnlimitHistory(
            date: data['date'].toString(),
            no: data['no'].toString(),
            amount: data['amount'].toString(),
            withdrawType: data['withdrawType'].toString(),
            withdrawAccount: data['withdrawAccount'].toString(),
            type: data['type'].toString(),
          ),
        );
      }
    }
    return _history;
  } catch (error) {
    throw error;
  }
}

///我的玩家
Future<List<UnlimitDirectPlayers>> fetchDirectPlayers() async {
  List<UnlimitDirectPlayers> _directPlayers = [];
  try {
    final responseData = await post(
      path: 'api/unlimit/directPlayers',
    );
    var directPlayersData = responseData['data'] as List<dynamic>;
    if (directPlayersData.length > 0) {
      for (var data in directPlayersData) {
        _directPlayers.add(
          UnlimitDirectPlayers(
            memberId: data['member_id'].toString(),
            member: data['member'].toString(),
            memberContribution: data['memberContribution'].toString(),
            memberCount: data['memberCount'].toString(),
            memberType: data['memberType'].toString(),
            teamContribution: data['teamContribution'].toString(),
            teamCount: data['teamCount'].toString(),
            type: data['type'].toString(),
          ),
        );
      }
    }
    return _directPlayers;
  } catch (error) {
    throw error;
  }
}

///我的業績
Future<List<UnlimitReward>> fetchReward() async {
  List<UnlimitReward> _reward = [];
  try {
    final responseData = await post(
      path: 'api/unlimit/reward',
    );
    var rewardData = responseData['data'] as List<dynamic>;
    if (rewardData.length > 0) {
      for (var data in rewardData) {
        _reward.add(
          UnlimitReward(
            date: data['date'].toString(),
            totalContribution: data['totalContribution'].toString(),
            teamContribution: data['teamContribution'].toString(),
            memberContribution: data['memberContribution'].toString(),
            reward: data['reward'].toString(),
            type: data['type'].toString(),
          ),
        );
      }
    }
    return _reward;
  } catch (error) {
    throw error;
  }
}

///申請提現
Future<void> withdraw(double amount) async {
  try {
    await post(
      path: 'api/unlimit/withdraw',
      parameters: {'amount': amount},
    );
  } catch (error) {
    throw error;
  }
}

///佣金制度表
Future<List<UnlimitList>> fetchUnlimitList() async {
  List<UnlimitList> _list = [];
  try {
    final responseData = await post(
      path: 'api/unlimit/list',
    );
    var listData = responseData['data'] as List<dynamic>;
    if (listData.length > 0) {
      for (var data in listData) {
        _list.add(
          UnlimitList(
            level: data['level'].toString(),
            bounsAmount: data['bounsAmount'].toString(),
            minAmount: data['minAmount'].toString(),
            maxAmount: data['maxAmount'].toString(),
            rebateRate: data['rebateRate'].toString(),
          ),
        );
      }
    }
    return _list;
  } catch (error) {
    throw error;
  }
}

///佣金制度表
Future<List<UnlimitVendorRate>> fetchUnlimitVendorRate() async {
  List<UnlimitVendorRate> _list = [];
  try {
    final responseData = await post(
      path: 'api/vendor/rate/list',
    );
    var listData = responseData['data'] as List<dynamic>;
    if (listData.length > 0) {
      for (var data in listData) {
        _list.add(
          UnlimitVendorRate(
            id: data['id'].toString(),
            name: data['name'].toString(),
            unlimitRate: data['unlimit_rate'].toString(),
          ),
        );
      }
    }
    return _list;
  } catch (error) {
    throw error;
  }
}

//******************************Record */

//紅包記錄列表
//1:發送記錄2:領取記綠3:用戶手動退回OR系統退還
Future<List<RedEnvelope>> fetchRedEnvelopes(int type) async {
  List<RedEnvelope> redEnvelopes = [];
  try {
    final responseData = await post(
      path: 'api/transaction/redEnvelope',
      parameters: {'type': type, 'page': 1, 'per_page': 999},
    );
    final redEnvelopeData = responseData['data'] as List<dynamic>;
    for (var data in redEnvelopeData) {
      redEnvelopes.add(
        RedEnvelope(
          id: data['id'].toString(),
          createdAt: data['created_at'].toString(),
          nickname: data['nickname'].toString(),
          point: data['point'].toString(),
        ),
      );
    }
    return redEnvelopes;
  } catch (error) {
    throw error;
  }
}

///充值訂單紀錄
Future<List<Order>> fetchOrder() async {
  List<Order> orderList = [];
  try {
    final responseData = await post(
      path: 'api/orders',
      parameters: {'vendor_id': "", 'page': 1, 'per_page': 999},
    );
    final orderData = responseData['data'] as List<dynamic>;
    for (var data in orderData) {
      orderList.add(
        Order(
          amount: data['amount'].toString(),
          balance: data['balance'].toString(),
          createdAt: data['created_at'].toString(),
          currencyId: data['currency_id'].toString(),
          id: data['id'].toString(),
          memberId: data['member_id'].toString(),
          orderType: data['order_type'].toString(),
          point: data['point'].toString(),
          status: data['status'].toString(),
          statusName: data['status_name'].toString(),
          updatedAt: data['updated_at'].toString(),
        ),
      );
    }
    return orderList;
  } catch (error) {
    throw error;
  }
}

///充值訂單紀錄
Future<List<WithdrawalRecord>> fetchWithdrawalRecord() async {
  List<WithdrawalRecord> recordList = [];
  try {
    final responseData = await post(
      path: 'api/withdrawal/list',
      parameters: {'vendor_id': "", 'page': 1, 'per_page': 999},
    );
    final orderData = responseData['data'] as List<dynamic>;
    for (var data in orderData) {
      recordList.add(
        WithdrawalRecord(
          amount: data['amount'].toString(),
          createdAt: data['created_at'].toString(),
          id: data['id'].toString(),
          status: data['status'].toString(),
        ),
      );
    }
    return recordList;
  } catch (error) {
    throw error;
  }
}

///中獎列表
Future<List<Prize>> fetchPrize() async {
  List<Prize> prizes = [];
  try {
    final responseData = await post(
      path: 'api/prizes',
      parameters: {'page': 1, 'per_page': 999},
    );
    final prizeData = responseData['data'] as List<dynamic>;

    for (var data in prizeData) {
      prizes.add(
        Prize(
          id: data['id'].toString(),
          point: data['point'].toString(),
          name: data['name'].toString(),
          eventName: data['event_name'].toString(),
          updatedAt: data['updated_at'].toString(),
        ),
      );
    }
    return prizes;
  } catch (error) {
    throw error;
  }
}

///轉點記錄列表
Future<List<Transaction>> fetchTransaction() async {
  List<Transaction> transactions = [];
  try {
    final responseData = await post(
      path: 'api/transactions',
      parameters: {'vendor_id': "", 'page': 1, 'per_page': 999},
    );
    final transactionData = responseData['data'] as List<dynamic>;

    for (var data in transactionData) {
      transactions.add(
        Transaction(
          id: data['id'].toString(),
          balance: data['balance'].toString(),
          createdAt: data['created_at'].toString(),
          point: data['point'].toString(),
          rate: data['rate'].toString(),
          status: data['status'].toString(),
          type: data['type'].toString(),
          updatedAt: data['updated_at'].toString(),
          vendorBalance: data['vendor_balance'].toString(),
          vendorId: data['vendor_id'].toString(),
          vendorName: data['vendor_name'].toString(),
          vendorPoint: data['vendor_point'].toString(),
        ),
      );
    }
    return transactions;
  } catch (error) {
    throw error;
  }
}

//客服轉帳記錄列表
Future<List<CustomerService>> fetchCustomerService() async {
  List<CustomerService> customerServices = [];
  try {
    final responseData = await post(
      path: 'api/transaction/customerService',
      parameters: {'vendor_id': "", 'page': 1, 'per_page': 999},
    );
    final customerServicesData = responseData['data'] as List<dynamic>;

    for (var data in customerServicesData) {
      customerServices.add(
        CustomerService(
          id: data['id'].toString(),
          createdAt: data['created_at'].toString(),
          point: data['point'].toString(),
          transactioinNo: data['transactioin_no'].toString(),
          type: data['type'].toString(),
        ),
      );
    }
    return customerServices;
  } catch (error) {
    throw error;
  }
}

//********************Wallet */

///獲取訂單類型列表
Future<List<WalletOrderType>> fetchOrderTypes() async {
  List<WalletOrderType> orderTypes = [];
  try {
    final responseData = await post(path: 'api/deposit/getOrderType');
    var orderData = responseData['data'] as List<dynamic>;
    if (orderData.length > 0) {
      for (var data in orderData) {
        List<OrderTypeInfo> orderTypeInfo = [];
        var infoData = data['order_type_info'] as List<dynamic>;
        if (infoData != null) {
          for (var info in infoData) {
            orderTypeInfo.add(OrderTypeInfo(
              display: info['display'].toString(),
              name: info['name'].toString(),
              option: null,
              placeholder: info['placeholder'].toString(),
              require: info['require'].toString(),
              type: info['type'].toString(),
            ));
          }
        }
        orderTypes.add(
          WalletOrderType(
            orderTypeId: data['order_type_id'].toString(),
            orderTypeInfo: orderTypeInfo,
            name: data['name'].toString(),
            imagePath: data['image_path'].toString(),
            minAmount: data['min_amount'].toString(),
            maxAmount: data['max_amount'].toString(),
            availableAmount: data['available_amount'].toString(),
            rate: data['rate'].toString(),
            cfId: data['cf_id'].toString(),
            cfName: data['cf_name'].toString(),
            url: data['url'].toString(),
          ),
        );
      }
    }
    return orderTypes;
  } catch (error) {
    throw error;
  }
}

///新增訂單
Future<DepositOrder> createOrder(String ordertypeId,
    {String amount, String qrcode}) async {
  try {
    Map<String, dynamic> parameters = {'order_type_id': ordertypeId};
    if (amount != null) {
      parameters['amount'] = amount;
    }
    if (qrcode != null) {
      parameters['pin'] = qrcode;
    }
    final responseData =
        await post(path: 'api/deposit/order', parameters: parameters);
    //type: 0 失敗, 1 轉址, 2 QRCode,3 Html
    DepositOrder depositOrder = DepositOrder(
      type: responseData['data']['type'].toString(),
      url: responseData['data']['url'].toString(),
      html: responseData['data']['html'].toString(),
      code: responseData['data']['code'].toString(),
    );
    return depositOrder;
  } catch (error) {
    throw error;
  }
}

//************自助上分 */

///是否顯示自助上分按鈕
Future<bool> hasAutoDespoiste() async {
  try {
    final responseData = await post(path: 'api/bank/hasAutoDespoiste');
    return responseData['data'] == 1;
  } catch (error) {
    throw error;
  }
}

///取得銀行帳號
Future<BankAccount> fetchBankAccount() async {
  try {
    final responseData = await post(path: 'api/bank/getAccount');
    // type : 1:查無訂單，2:已有訂單
    var accountData = responseData['data'];
    var bankData = accountData['bank'] as List<dynamic>;
    List<Bank> bankList = bankData
        .map(
          (bank) => Bank(
            orderTypeId: bank['order_type_id'].toString(),
            bankId: bank['bank_id'].toString(),
            bankName: bank['bank_name'].toString(),
            bankCode: bank['bank_code'].toString(),
            accountName: bank['account_name'].toString(),
            accountNo: bank['account_no'].toString(),
            minAmount: bank['min_amount'].toString(),
            maxAmount: bank['max_amount'].toString(),
          ),
        )
        .toList();
    return BankAccount(
      type: accountData['type'].toString(),
      allBank: bankList,
    );
  } catch (error) {
    throw error;
  }
}

///創建訂單，並回傳銀行訂單
Future<BankOrder> createBankOrder({String bankId, String amount}) async {
  try {
    var responseData;
    if (bankId == null) {
      responseData = await post(path: 'api/bank/order', parameters: {});
    } else {
      responseData = await post(
        path: 'api/bank/order',
        parameters: {
          'bank_id': bankId,
          'amount': amount,
        },
      );
    }

    var orderData = responseData['data'];
    return BankOrder(
      orderId: orderData['order_id'].toString(),
      orderNo: orderData['order_no'].toString(),
      bankName: orderData['bank_name'].toString(),
      bankCode: orderData['bank_code'].toString(),
      accountName: orderData['account_name'].toString(),
      accountNo: orderData['account_no'].toString(),
      amount: orderData['amount'].toString(),
      status: orderData['status'].toString(),
    );
  } catch (error) {
    throw error;
  }
}

///刪除訂單
Future<void> deleteBankOrder(String orderId) async {
  try {
    await post(
      path: 'api/bank/delete',
      parameters: {'order_id': orderId},
    );
  } catch (error) {
    throw error;
  }
}

///上傳收據
Future<String> uploadBankReceiptImage(
    String orderId, String base64Image) async {
  final parameters = {
    "order_id": orderId,
    "image": base64Image,
  };
  try {
    final responseData = await post(
      path: 'api/bank/uploadImage',
      parameters: parameters,
    );
    return responseData['data']['amount'].toString();
  } catch (error) {
    throw (error);
  }
}
//************洗分 */

///檢查是否有綁定銀行帳號
Future<WithdrawalInfo> fetchWithdrawalInfo() async {
  try {
    var responseData = await post(path: 'api/withdrawal/check');
    var data = responseData['data'];
    return WithdrawalInfo(
      bankCode: data['bank_code'].toString(),
      bankName: data['bank_name'].toString(),
      accountName: data['account_name'].toString(),
      accountNo: data['account_no'].toString(),
      mobile: data['mobile'].toString(),
    );
  } catch (error) {
    throw error;
  }
}

///取得銀行清單
Future<List<WithdrawalBank>> fetchWithdrawalBank() async {
  try {
    var responseData = await post(path: 'api/withdrawal/getBankList');
    var data = responseData['data'] as List<dynamic>;
    var bankList = data
        .map(
          (bank) => WithdrawalBank(
            bankId: bank['bank_id'].toString(),
            bankName: bank['bank_name'].toString(),
            bankCode: bank['bank_code'].toString(),
          ),
        )
        .toList();
    return bankList;
  } catch (error) {
    throw error;
  }
}

///綁定銀行帳號
Future<void> bindWithdrawalBank({
  String bankId,
  String accountName,
  String accountNo,
}) async {
  try {
    await post(path: 'api/withdrawal/account', parameters: {
      'bank_id': bankId,
      'account_name': accountName,
      'account_no': accountNo
    });
  } catch (error) {
    throw error;
  }
}

///創建自助洗分訂單
Future<void> createWithdrawalOrder({
  String amount,
}) async {
  try {
    await post(path: 'api/withdrawal', parameters: {
      'amount': amount,
    });
  } catch (error) {
    throw error;
  }
}

///稽核流水
Future<List<FlowLimit>> fetchFlowLimit() async {
  try {
    var responseData = await post(path: 'api/flowLimit/show');
    var data = responseData['data'] as List<dynamic>;
    var flowLimitList = data
        .map(
          (bank) => FlowLimit(
            reach: bank['reach'].toString(),
            flowLimit: bank['flow_limit'].toString(),
          ),
        )
        .toList();
    return flowLimitList;
  } catch (error) {
    throw error;
  }
}

//*******************************Auth */
///傳送驗證碼 type 1:資料驗證2:忘記密碼3:找回密碼
Future<void> sendValidateCode(int type, String mobile, String memberId) async {
  await post(
    path: 'api/sendValidateCode',
    parameters: {
      "type": type,
      "verify_by": 1, //1:手機2Email
      "member_id": memberId,
      "mobile": mobile,
    },
  );
}

///確認驗證碼 確認後回傳username
Future<String> checkValidateCode(String token) async {
  final responseData = await post(
    path: 'api/checkValidateCode',
    parameters: {"token": token},
  );
  String username = responseData['data'].toString();
  return username;
}

///國家列表
Future<List<Country>> fetchCountries() async {
  List<Country> countries = [];

  final responseData = await post(path: 'api/countries', parameters: {
    'domain': currentEnv.domain,
    'agent_id': currentEnv.agentId,
  });

  var countriesData = responseData['data'] as List<dynamic>;
  for (var data in countriesData) {
    countries.add(
      Country(
        id: data['id'].toString(),
        code: data['code'].toString(),
        name: data['name'].toString(),
        countryCode: data['country_code'].toString(),
        phoneRule: data['phone_rule'].toString(),
      ),
    );
  }
  return countries;
}

//**************************Member */

Future<void> updateImage(String base64Image) async {
  final parameters = {
    "image": base64Image,
  };
  try {
    await post(
      path: 'api/update',
      parameters: parameters,
    );
  } catch (error) {
    throw (error);
  }
}

Future<void> updateNickname(String name) async {
  await post(
    path: 'api/update',
    parameters: {"nickname": name},
  );
  MemberController.to.updateNickname(name);
}

//*******************************平台群發*/

///取得未讀群發訊息
Future<AnnouncementMessage> fetchAnnouncementMessage() async {
  try {
    final ts = DateTime.now().millisecondsSinceEpoch.toString();
    final responseData = await post(
      path: 'api/announcement-messages/list?ts=' + ts,
    );
    var data = responseData['data'];
    if (data == null) {
      return null;
    } else {
      return AnnouncementMessage(
        announcementMessageId: data['announcement_message_id'].toString(),
        title: data['title'].toString(),
        message: data['message'].toString(),
        createdAt: data['created_at'].toString(),
        type: data['type'].toString(),
      );
    }
  } catch (error) {
    throw (error);
  }
}

///設置已讀，關閉訊息視窗呼叫
Future<void> announcementMessageRead(String announcementMessageId) async {
  try {
    await post(
        path: 'api/announcement-messages/read',
        parameters: {'announcement_message_id': announcementMessageId});
  } catch (error) {
    throw (error);
  }
}
