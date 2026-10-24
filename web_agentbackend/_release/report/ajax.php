<?php
require_once('../_init.php');
switch ($_REQUEST['action']) {
    case "sr02":
        echo sr02();
        break;
    case "sr03":
        echo sr03();
        break;
    case "sr05":
        echo sr05();
        break;
    case "sr06":
        echo sr06();
        break;
    case "sr07":
        echo sr07();
        break;
    case "sr08":
        echo sr08();
        break;
    case "sr08":
        echo sr08();
        break;
    case "sr11":
        echo sr11('total');
        break;
    case "sr11_detail":
        echo sr11('detail');
        break;
    case "sr11_member":
        echo sr11Member();
        break;
    case "newsr11":
        echo newsr11('total');
        break;
    case "newsr11_detail":
        echo newsr11('detail');
        break;
    case "newsr11_member":
        echo newsr11Member();
        break;
    case "sr12":
        echo sr12();
        break;
    case "sr13":
        echo sr13();
        break;
    case "sr14":
        echo sr14();
        break;
    case "sr15_excel":
        echo sr15_excel();
        break;
    case "sr16":
        echo sr16();
        break;
    case "sr16_period":
        echo sr16Period();
        break;
    case "sr16_member":
        echo sr16Member();
        break;
    case "sr16_ticket":
        echo sr16Ticket();
        break;
    case "sr16_winning_list":
        echo sr16WinningList();
        break;
    case "sr17_period":
        echo sr17Period();
        break;
    case "sr17_member":
        echo sr17Member();
        break;
    case "sr17_ticket":
        echo sr17Ticket();
        break;
    case "sr17_winning_list":
        echo sr17WinningList();
        break;
    case "transactionList":
        echo transactionList();
        break;
    case "transactionListNew":
        echo transactionListNew();
        break;
        //活動獎勵獲獎記錄
    case 'prize_report':
        echo prizeReport();
        break;
}

function sr02()
{
    global $lang;
    $title = $lang['sr02'];
    $header  = [
        ['title' => $lang['num'], 'width' => 15],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['source'], 'width' => 15],
        ['title' => $lang['deposit'] . '/' . $lang['withdrawal'], 'width' => 15],
        ['title' => $lang['point'], 'width' => 15]
    ];
    $data = array();
    if ($_REQUEST['type'] == 1) {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['source'] = $_REQUEST['source'] ? $_REQUEST['source'] : 0;
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':59';
    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $member = curlPost('api/report/sr02Member', $data);

    $result = array();
    foreach ($member['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['point'] = number_format($row['point'], 2);
            $row['balance'] = number_format($row['balance'], 2);
        } else {
            $row['point'] = number_format(($row['point'] / 1000), 2) . 'K';
            $row['balance'] = number_format(($row['balance'] / 1000), 2) . 'K';
        }

        $row['source'] = ($row['source'] == 1) ? $lang['cs'] : $lang['onlinepay'];
        $row['type'] = ($row['type'] == 1 ? $lang['deposit'] : $lang['withdrawal']);
        $result[] = $row;
    }
    return excel($title, $header, $result, $start, $end);
}

function sr03()
{
    global $lang;
    $title = $lang['sr03'];
    $header  = [
        ['title' => $lang['member_account'], 'width' => 20],
        ['title' => $lang['member_total_deposit'], 'width' => 15],
        ['title' => $lang['member_total_withdrawl'], 'width' => 15],
        ['title' => $lang['total_deposit'] . '-' . $lang['total_withdrawl'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 20]
    ];
    $data = array();
    if ($_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    }
    $start = $_REQUEST['start_date'];
    $end = $_REQUEST['end_date'];

    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $member = curlPost('api/report/sr03Member', $data);
    $result = array();
    foreach ($member['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['total_in'] = number_format($row['total_in'], 2);
            $row['total_out'] = number_format($row['total_out'], 2);
            $row['difference'] = number_format(($row['total_in'] - $row['total_out']), 2);
        } else {
            $row['total_in'] = number_format(( $row['total_in'] / 1000), 2) . 'K';
            $row['total_out'] = number_format(($row['total_out'] / 1000), 2) . 'K';
            $row['difference'] = number_format((($row['total_in'] - $row['total_out'] / 1000)), 2) . 'K';
        }
        $result[] = [
            $row['member_username'],
            $row['total_in'],
            $row['total_out'],
            $row['difference'],
            $row['agent_username'],
        ];
    }
    return excel($title, $header, $result, $start, $end);
}

function sr05()
{
    global $lang;
    $title = $lang['sr05'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['ordernum'], 'width' => 20],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 10],
        ['title' => $lang['member_account'], 'width' => 20],
        ['title' => $lang['member_nickname'], 'width' => 20],
        ['title' => $lang['change_point'], 'width' => 15],
        ['title' => $lang['moment_point'], 'width' => 15],
        ['title' => $lang['execution_account'], 'width' => 20],
        ['title' => $lang['order_status'], 'width' => 10],
        ['title' => $lang['memo'], 'width' => 20],
    ];
    $data = array();
    if ($_REQUEST['type'] == 1) {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }
    $start = $_REQUEST['start_date'];
    $end = $_REQUEST['end_date'];

    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $member = curlPost('api/report/sr05', $data);
    $result = array();
    foreach ($member['data'] as $row) {
        $row['type'] = ($row['type'] == 1 ? $lang['transferin'] : $lang['transferout']);
        $row['status'] = ($row['status'] == 1) ? $lang['success'] : $lang['failure'];
        $result[] = $row;
    }
    return excel($title, $header, $result, $start, $end);
}

function sr06()
{
    global $lang;
    $title = $lang['sr06'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['ordernum'], 'width' => 15],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['type'], 'width' => 10],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['member_nickname'], 'width' => 20],
        ['title' => $lang['change_point'], 'width' => 10],
        ['title' => $lang['moment_point'], 'width' => 10],
        ['title' => $lang['status'], 'width' => 10],
    ];
    $data = array();
    if ($_REQUEST['type'] == 1) {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }
    $start = $_REQUEST['start_date'];
    $end = $_REQUEST['end_date'];

    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $data['status'] = $_REQUEST['status'];
    $data['vendor_id'] = isset($_REQUEST['vendor_id']) ? $_REQUEST['vendor_id'] : 0;
    $data['page'] = 1;
    $data['per_page'] = 10000;
    $member = curlPost('api/report/sr06', $data);
    $result = array();
    foreach ($member['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['point'] = number_format($row['point'], 2);
            $row['balance'] = number_format($row['balance'], 2);
        } else {
            $row['point'] = number_format(( $row['point'] / 1000), 2) . 'K';
            $row['balance'] = number_format(($row['balance'] / 1000), 2) . 'K';
        }
        $row['type'] = ($row['type'] == 1 ? $lang['transferout'] : $lang['transferin']);
        $row['point'] = ($row['type'] == 1) ? -$row['point'] : $row['point'];
        $row['status'] = ($row['status'] == 1000 ? $lang['success'] : $lang['failure']);
        $result[] = [
            $row['id'],
            $row['transaction_no'],
            $row['created_at'],
            $row['agent_username'],
            $row['vendor'],
            $row['type'],
            $row['member_username'],
            $row['member_nickname'],
            $row['point'],
            $row['balance'],
            $row['status']
        ];
    }
    return excel($title, $header, $result, $start, $end);
}

function sr07()
{
    global $lang;
    $title = $lang['sr07'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['send_account'], 'width' => 20],
        ['title' => $lang['accept_account'], 'width' => 20],
        ['title' => $lang['point'], 'width' => 15],
        ['title' => $lang['handling_fee'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 15],
    ];
    $data = array();

    $data['username'] = $_GET['username'];
    $start_date = $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d', strtotime('-1day'));
    $end_date = $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d');

    $data['start_time'] = $start_date . ' 00:00:00' ;
    $data['end_time'] = $end_date . ' 23:59:59' ;
    $data['page'] = 1;
    $data['per_page'] = 10000;
    //$member = curlPost('api/report/sr07', $data);
    $client = new \target\resource\Clients\Client('internal');
    $response = $client->send('ReportSR07', 'GET', [], $data);
    if ($response->statusCode != 200) {
        exit();
    }
    $member = (array) $response->data;
    $result = array();
    $a = 0;
    foreach ($member['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['amount'] = number_format($row['amount'], 2);
            $row['tax'] = number_format($row['tax'], 2);
        } else {
            $row['amount'] = number_format(( $row['amount'] / 1000), 2) . 'K';
            $row['tax'] = number_format(($row['tax'] / 1000), 2) . 'K';
        }
        $row = (array) $row;
        $result[$a]['id'] = $row['id'];
        $result[$a]['created_at'] = $row['created_at'];
        $result[$a]['self_username'] = $row['self_username'];
        $result[$a]['other_username'] = $row['other_username'];
        $result[$a]['amount'] = $row['amount'];
        $result[$a]['tax'] = $row['tax'];
        $arr_status = [$lang['failure'],$lang['unaccalimed'],$lang['success_received'],$lang['cancel'],$lang['expired']];
        $result[$a]['status'] = $arr_status[$row['status']];
        $a++;
    }
    return excel($title, $header, $result, $start_date, $end_date);
}

function sr08()
{
    global $lang;
    $title = $lang['sr08'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['send_account'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 20],
        ['title' => $lang['accept_account'], 'width' => 20],
        ['title' => $lang['point'], 'width' => 15],
        ['title' => $lang['balance'], 'width' => 15],
        ['title' => $lang['memo'], 'width' => 20],
        ['title' => $lang['status'], 'width' => 15],
    ];
    $data = array();

    $data['username'] = $_GET['username'];
    $start_date = $_GET['start_date'] ? $_GET['start_date'] : date('Y-m-d');
    $end_date = $_GET['end_date'] ? $_GET['end_date'] : date('Y-m-d');

    $data['start_time'] = $start_date . ' 00:00:00' ;
    $data['end_time'] = $end_date . ' 23:59:59' ;
    $data['page'] = 1;
    $data['per_page'] = 10000;

    $record = curlPost('api/report/sr08', $data);

    $result = array();
    $a = 0;
    foreach ($record['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['point'] = number_format($row['point'], 2);
            $row['balance'] = number_format($row['balance'], 2);
        } else {
            $row['point'] = number_format(( $row['point'] / 1000), 2) . 'K';
            $row['balance'] = number_format(($row['balance'] / 1000), 2) . 'K';
        }

        $row = (array) $row;
        $result[$a]['id'] = $row['id'];
        $result[$a]['created_at'] = $row['created_at'];
        $result[$a]['operator'] = $row['operator'];
        $result[$a]['agent_username'] = $row['agent_username'];
        $result[$a]['member_username'] = $row['member_username'];
        $result[$a]['point'] = $row['point'];
        $result[$a]['balance'] = $row['balance'];
        $result[$a]['remark'] = $row['remark'];
        $result[$a]['status'] = ($row['status'] == 1000)?$lang['success_received']:$lang['failure'];
        $a++;
    }
    return excel($title, $header, $result, $start_date, $end_date);
}

function sr11($type)
{
    global $lang;

    //取對應語言包
    $translates = curlPost('api/translate');
    $lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
    $lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
    $lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
    $lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

    $title = $lang['sr11'];

    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':00';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    $data['game_type'] = $_REQUEST['game_type'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['user_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    if ($type == 'total') {
        $record = curlPost('api/report/platformReconciliationTotal', $data);
    } elseif ($type == 'detail') {
        $data['page'] = 1;
        $data['per_page'] = 10000;
        $record = curlPost('api/report/platformReconciliationDetail', $data);
    }
    $header  = [
        ['title' => $lang['nextlevel_detail'], 'width' => 15],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['agent_name'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['game_type'], 'width' => 10],
        ['title' => $lang['total_bet'], 'width' => 15],
        ['title' => $lang['total_winlose'], 'width' => 15],
        ['title' => $lang['commission'], 'width' => 15],
        ['title' => $lang['win_jackpot'], 'width' => 15]
    ];
    if ($_REQUEST['cost_visible'] == 1) {
        $header = array_merge(
            $header,
            [['title' => $lab_cost, 'width' => 15]]
        );
    }
    if ($record['show_v_jackpot'] == true) {
        $header = array_merge(
            $header,
            [['title' => $lab_jackpot, 'width' => 15]]
        );
    }
    $header = array_merge(
        $header,
        [['title' => $lang['total_score'], 'width' => 15]]
    );

    $result = array();
    foreach ($record['data'] as $row) {
        $type = ($row['vendor_id'] == -2 && $row['type'] == 'self') ? '-' : $lang[$row['type']];
        $bite_score = $row['lottery'];
        if ($record['show_v_jackpot'] == true) {
            $bite_score += $row['v_jackpot'];
        }
        if ($_REQUEST['cost_visible'] == 1) {
            $bite_score += $row['cost'];
        }
        $bite_score += ($row['vendor_id'] == 36) ? $row['commission'] : $row['win_lose'];
        if($_SESSION['toggle_thousands'] == 0) {
            $bite_score = number_format($bite_score, 4);
            $row['commission'] = number_format($row['commission'], 4);
            $row['lottery'] = number_format($row['lottery'], 4);
            $row['v_jackpot'] = number_format($row['v_jackpot'], 4);
            if(is_numeric($row['total_bet'])) {
                $row['lottery'] = number_format($row['total_bet'], 4);
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format($row['win_lose'], 4);
            }
        } else {
            $bite_score = number_format(( $bite_score / 1000), 4) . 'K';
            $row['commission'] = number_format(( $row['commission'] / 1000), 4) . 'K';
            $row['lottery'] = number_format(( $row['lottery'] / 1000), 4) . 'K';
            $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 4) . 'K';
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format(( $row['total_bet'] / 1000), 4) . 'K';
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format(( $row['win_lose'] / 1000), 4) . 'K';
            }
        }
        $tmp = [
            $type,
            $row['agent_username'],
            $row['agent_nickname'],
            ($row['vendor'] == 'payfee') ? $lab_pay_fee : ($row['vendor'] == 'smsfee' ? $lab_sms_fee : $row['vendor']),
            $row['game_type'],
            (($row['vendor_id'] == 36) ? '--' : $row['total_bet']),
            (($row['vendor_id'] == 36) ? '--' : $row['win_lose']),
            $row['commission'],
            $row['lottery']
        ];

        if ($_REQUEST['cost_visible'] == 1) {
            if($_SESSION['toggle_thousands'] == 0) {
                $row['cost'] = number_format($row['cost'], 4);
            } else {
                $row['cost'] = number_format(( $row['cost'] / 1000), 4) . 'K';
            }
            $tmp = array_merge($tmp, [$row['cost']]);
        }
        $tmp = array_merge(
            $tmp,
            (
            ($record['show_v_jackpot'] == true)
                ? [$row['v_jackpot'], $bite_score]
                : [$bite_score]
            )
        );
        $result[] = $tmp;
    }
    return excel($title, $header, $result, $start, $end);
}

function newsr11($type)
{
    global $lang;

    //取對應語言包
    $translates = curlPost('api/translate');
    $lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
    $lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
    $lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
    $lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

    $title = $lang['sr11'];

    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':00';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    $data['game_type'] = $_REQUEST['game_type'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['user_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    if ($type == 'total') {
        $record = curlPost('api/newreport/platformReconciliationTotal', $data);
    } elseif ($type == 'detail') {
        $data['page'] = 1;
        $data['per_page'] = 10000;
        $record = curlPost('api/newreport/platformReconciliationDetail', $data);
    }

    $header  = [
        ['title' => $lang['nextlevel_detail'], 'width' => 15],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['agent_name'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['game_type'], 'width' => 10],
        ['title' => $lang['total_bet'], 'width' => 15],
        ['title' => $lang['total_winlose'], 'width' => 15],
        ['title' => $lang['commission'], 'width' => 15],
        ['title' => $lang['win_jackpot'], 'width' => 15]
    ];
    if ($_REQUEST['cost_visible'] == 1) {
        $header = array_merge(
            $header,
            [['title' => $lab_cost, 'width' => 15]]
        );
    }
    if ($record['show_v_jackpot'] == true) {
        $header = array_merge(
            $header,
            [['title' => $lab_jackpot, 'width' => 15]]
        );
    }
    $header = array_merge(
        $header,
        [['title' => $lang['total_score'], 'width' => 15]]
    );

    $result = array();
    foreach ($record['data'] as $row) {
        $type = ($row['vendor_id'] == -2 && $row['type'] == 'self') ? '-' : $lang[$row['type']];
        $bite_score = $row['lottery'];
        if ($record['show_v_jackpot'] == true) {
            $bite_score += $row['v_jackpot'];
        }
        if ($_REQUEST['cost_visible'] == 1) {
            $bite_score += $row['cost'];
        }
        $bite_score += ($row['vendor_id'] == 36) ? $row['commission'] : $row['win_lose'];

        if($_SESSION['toggle_thousands'] == 0) {
            $bite_score = number_format($bite_score, 4);
            $row['commission'] = number_format($row['commission'], 4);
            $row['lottery'] = number_format($row['lottery'], 4);
            $row['v_jackpot'] = number_format($row['v_jackpot'], 4);
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format($row['total_bet'], 4);
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format($row['win_lose'], 4);
            }
        } else {
            $bite_score = number_format(( $bite_score / 1000), 4) . 'K';
            $row['commission'] = number_format(( $row['commission'] / 1000), 4) . 'K';
            $row['lottery'] = number_format(( $row['lottery'] / 1000), 4) . 'K';
            $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 4) . 'K';
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format(( $row['total_bet'] / 1000), 4) . 'K';
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format(( $row['win_lose'] / 1000), 4) . 'K';
            }
        }

        $tmp = [
            $type,
            $row['agent_username'],
            $row['agent_nickname'],
            ($row['vendor'] == 'payfee') ? $lab_pay_fee : ($row['vendor'] == 'smsfee' ? $lab_sms_fee : $row['vendor']),
            $row['game_type'],
            (($row['vendor_id'] == 36) ? '--' : $row['total_bet']),
            (($row['vendor_id'] == 36) ? '--' : $row['win_lose']),
            $row['commission'],
            $row['lottery']
        ];
        if ($_REQUEST['cost_visible'] == 1) {
            $row['cost'] = ($_SESSION['toggle_thousands'] == 0)
                ? number_format($row['cost'], 4)
                : number_format(( $row['cost'] / 1000), 4) . 'K';
            $tmp = array_merge($tmp, [$row['cost']]);
        }
        $tmp = array_merge(
            $tmp,
            (
                ($record['show_v_jackpot'] == true)
                    ? [$row['v_jackpot'], $bite_score]
                    : [$bite_score]
            )
        );
        $result[] = $tmp;
    }

    return excel($title, $header, $result, $start, $end);
}

function sr11Member()
{
    global $lang;
    //取對應語言包
    $translates = curlPost('api/translate');
    $lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
    $lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
    $lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
    $lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

    $title = $lang['sr11_1'];
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':00';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    $data['game_type'] = $_REQUEST['game_type'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['user_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    $path = 'report';
    $record = curlPost('api/' . $path . '/platformReconciliationMember', $data);

    $header  = [
        ['title' => $lang['num'], 'width' => 15],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['member_name'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['game_type'], 'width' => 10],
        ['title' => $lang['total_bet'], 'width' => 15],
        ['title' => $lang['total_winlose'], 'width' => 15],
        ['title' => $lang['commission'], 'width' => 15],
        ['title' => $lang['win_jackpot'], 'width' => 15]
    ];
    if ($_REQUEST['cost_visible'] == 1) {
        $header = array_merge(
            $header,
            [['title' => $lab_cost, 'width' => 15]]
        );
    }
    if ($record['show_v_jackpot'] == true) {
        $header = array_merge(
            $header,
            [['title' => $lab_jackpot, 'width' => 15]]
        );
    }
    $header = array_merge(
        $header,
        [['title' => $lang['total_score'], 'width' => 15]]
    );

    $result = array();
    $i = 1;

    foreach ($record['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['total_score'] = ($record['show_v_jackpot'] == true)
                ? number_format(($row['win_lose'] + $row['lottery'] + $row['v_jackpot']), 4)
                : number_format(($row['win_lose'] + $row['lottery']), 4);
            $row['total_bet'] = number_format($row['total_bet'], 4);
            $row['win_lose'] = number_format($row['win_lose'], 4);
            $row['commission'] = number_format($row['commission'], 4);
            $row['lottery'] = number_format($row['lottery'], 4);
            if($record['show_v_jackpot'] == true){
                $row['v_jackpot'] = number_format($row['v_jackpot'], 4);
            }
        } else {
            $row['total_score'] = ($record['show_v_jackpot'] == true)
                ? number_format((($row['win_lose'] + $row['lottery'] + $row['v_jackpot']) / 1000), 4) . 'K'
                : number_format((($row['win_lose'] + $row['lottery']) / 1000), 4) . 'K';
            $row['total_bet'] = number_format(( $row['total_bet'] / 1000), 4) . 'K';
            $row['win_lose'] = number_format(( $row['win_lose'] / 1000), 4) . 'K';
            $row['commission'] = number_format(( $row['commission'] / 1000), 4) . 'K';
            $row['lottery'] = number_format(( $row['lottery'] / 1000), 4) . 'K';
            if($record['show_v_jackpot'] == true){
                $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 4) . 'K';
            }
        }

        $tmp = [
            $i++,
            $row['member_username'],
            $row['member_nickname'],
            ($row['vendor'] == 'payfee') ? $lab_pay_fee : ($row['vendor'] == 'smsfee' ? $lab_sms_fee : $row['vendor']),
            $row['game_type'],
            $row['total_bet'],
            $row['win_lose'],
            $row['commission'],
            $row['lottery']
        ];
        if ($_REQUEST['cost_visible'] == 1) {
            $row['cost'] = ($_SESSION['toggle_thousands'] == 0)
                ? number_format($row['cost'], 4)
                : number_format(( $row['cost'] / 1000), 4) . 'K';
            $tmp = array_merge($tmp, [$row['cost']]);
        }
        $tmp = array_merge(
            $tmp,
            (
            ($record['show_v_jackpot'] == true)
                ? [
                $row['v_jackpot'],
                $row['total_score']
            ]
                : [$row['total_score']]
            )
        );
        $result[] = $tmp;
    }

    return excel($title, $header, $result, $start, $end);
}

function newsr11Member()
{
    global $lang;
    //取對應語言包
    $translates = curlPost('api/translate');
    $lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
    $lab_pay_fee = isset($translates['data']['3006']) ? $translates['data']['3006'] : 'Pay fees';
    $lab_sms_fee = isset($translates['data']['3007']) ? $translates['data']['3007'] : 'SMS fee';
    $lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

    $title = $lang['sr11_1'];
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':00';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    $data['game_type'] = $_REQUEST['game_type'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['user_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    $record = curlPost('api/newreport/platformReconciliationMember', $data);

    $header  = [
        ['title' => $lang['num'], 'width' => 15],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['member_name'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['game_type'], 'width' => 10],
        ['title' => $lang['total_bet'], 'width' => 15],
        ['title' => $lang['total_winlose'], 'width' => 15],
        ['title' => $lang['commission'], 'width' => 15],
        ['title' => $lang['win_jackpot'], 'width' => 15]
    ];
    if ($_REQUEST['cost_visible'] == 1) {
        $header = array_merge(
            $header,
            [['title' => $lab_cost, 'width' => 15]]
        );
    }
    if ($record['show_v_jackpot'] == true) {
        $header = array_merge(
            $header,
            [['title' => $lab_jackpot, 'width' => 15]]
        );
    }
    $header = array_merge(
        $header,
        [['title' => $lang['total_score'], 'width' => 15]]
    );

    $result = array();
    $i = 1;
    foreach ($record['data'] as $row) {
        $bite_score = $row['lottery'];
        if ($record['show_v_jackpot'] == true) {
            $bite_score += $row['v_jackpot'];
        }
        $bite_score += ($row['vendor_id'] == 36) ? $row['commission'] : $row['win_lose'];
        if($_SESSION['toggle_thousands'] == 0) {
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format($row['total_bet'], 4);
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format($row['win_lose'], 4);
            }
            $row['commission'] = number_format($row['commission'], 4);
            $row['lottery'] = number_format($row['lottery'], 4);
            if($record['show_v_jackpot'] == true) {
                $row['v_jackpot'] = number_format($row['v_jackpot'], 4);
            }

        } else {
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format(( $row['total_bet'] / 1000), 4) . 'K';
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format(( $row['win_lose'] / 1000), 4) . 'K';
            }
            $row['commission'] = number_format(( $row['commission'] / 1000), 4) . 'K';
            $row['lottery'] = number_format(( $row['lottery'] / 1000), 4) . 'K';
            if($record['show_v_jackpot'] == true) {
                $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 4) . 'K';
            }

        }
        $tmp = [
            $i++,
            $row['member_username'],
            $row['member_nickname'],
            ($row['vendor'] == 'payfee') ? $lab_pay_fee : ($row['vendor'] == 'smsfee' ? $lab_sms_fee : $row['vendor']),
            $row['game_type'],
            (($row['vendor_id'] == 36) ? '--' : $row['total_bet']),
            (($row['vendor_id'] == 36) ? '--' : $row['win_lose']),
            $row['commission'],
            $row['lottery']
        ];
        if ($_REQUEST['cost_visible'] == 1) {
            $bite_score += $row['cost'];
            $bite_score = ($_SESSION['toggle_thousands'] == 0)
                ? number_format($bite_score, 4)
                : number_format(( $bite_score / 1000), 4) . 'K';
            $row['cost'] = ($_SESSION['toggle_thousands'] == 0)
                ? number_format($row['cost'], 4)
                : number_format(( $row['cost'] / 1000), 4) . 'K';
            $tmp = array_merge($tmp, [$row['cost']]);
        }
        $tmp = array_merge(
            $tmp,
            (
            ($record['show_v_jackpot'] == true)
                ? [$row['v_jackpot'], $bite_score]
                : [$bite_score]
            )
        );
        $result[] = $tmp;
    }

    return excel($title, $header, $result, $start, $end);
}

function sr12()
{
    global $lang;
    $title = $lang['sr12'];
    $header  = [
        // ['title' => '編號', 'width' => 10],
        ['title' => $lang['bet_time'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['game_name'], 'width' => 25],
        ['title' => $lang['bet'], 'width' => 15],
        ['title' => $lang['valid_bet'], 'width' => 15],
        ['title' => $lang['payout'], 'width' => 15],
        ['title' => $lang['winlose'], 'width' => 15],
        ['title' => $lang['commission'], 'width' => 15],
        ['title' => $lang['ordernumber'], 'width' => 15]
    ];
    $start = $_REQUEST['start_time'] . ':00';
    $end = $_REQUEST['end_time'] . ':59';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['game_no'] = $_REQUEST['username'];
    }
    $data['game_type'] = is_int($_REQUEST['game_type']) ? $_REQUEST['game_type'] : 0;
    $data['game_id'] = is_int($_REQUEST['game_id']) ? $_REQUEST['game_type'] : 0;
    $data['sort_by'] = $_REQUEST['sort_by'];
    $data['whitch_time'] = $_REQUEST['whitch_time'];
    $data['page'] = 1;
    $data['per_page'] = 10000;
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    $record = curlPost('api/report/sr12', $data);
    $result = array();
    foreach ($record['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['bet_amount'] = number_format($row['bet_amount'], 4);
            $row['valid_bet_amount'] = number_format($row['valid_bet_amount'], 4);
            $row['lottery'] = number_format($row['lottery'], 4);
            $row['win_lose'] = number_format($row['win_lose'], 4);
            $row['commission'] = number_format($row['commission'], 4);
        } else {
            $row['bet_amount'] = number_format(($row['bet_amount'] / 1000), 4) . 'K';
            $row['valid_bet_amount'] = number_format(($row['valid_bet_amount'] / 1000), 4) . 'K';
            $row['lottery'] = number_format(($row['lottery'] / 1000), 4) . 'K';
            $row['win_lose'] = number_format(($row['win_lose'] / 1000), 4) . 'K';
            $row['commission'] = number_format(($row['commission'] / 1000), 4) . 'K';
        }
        $result[] = [
            $row['bet_time'],
            $row['agent_username'],
            $row['member_username'],
            $row['game_name'],
            $row['bet_amount'],
            $row['valid_bet_amount'],
            $row['lottery'],
            $row['win_lose'],
            $row['commission'],
            $row['game_no']
        ];
    }

    return excel($title, $header, $result, $start, $end);
}

function sr13()
{
    global $lang;
    $title = $lang["menu_report_sr13"];
    $header  = [
        ['title' => $lang['time'], 'width' => 20],
        ['title' => $lang['ordernum'], 'width' => 25],
        ['title' => $lang['type'], 'width' => 15],
        ['title' => $lang['agent_account']."(from)", 'width' => 20],
        ['title' => $lang['agent_account']."(to)", 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['moment_point'], 'width' => 15],
        ['title' => $lang['change_point'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 15],
        ['title' => $lang['memo'], 'width' => 25],
    ];

    $start = $_REQUEST['start_time'];
    $end = $_REQUEST['end_time'];

    $data = array();
    $data['username'] = $_REQUEST['username'];
    $data['page'] = 1;
    $data['per_page'] = 10000;
    $data['start_time'] = $start;
    $data['end_time'] = $end;

    $client = new \target\resource\Clients\Client('internal');
    $result = $client->send('AgentMoneyChangeReport', 'GET', [], $data);

    foreach ($result->data->data as $row) {
        $row = (array) $row;
        $isAgent_to_Agent = $row["isAgent_to_Agent"];
        $prefixText =  "(".($isAgent_to_Agent ? $lang["agent"] : $lang["player"]).")";
        $type_name = $lang[$row["type_name_lang_key"]].$prefixText;
        $status_text =  $isAgent_to_Agent ? $lang["status_".$row["status"]] :  $lang["chat_transaction_status_".$row["status"]];
        if($_SESSION['toggle_thousands'] == 0) {
            $row['before_credit'] = ($row['before_credit'] == -1)
                ?  "∞"
                : number_format($row['before_credit'], 2);
            $row['amount'] = number_format($row['amount'], 2);
        } else {
            $row['before_credit'] = ($row['before_credit'] == -1)
                ?  "∞"
                : number_format(( $row['bet_amount'] / 1000), 2) . 'K';
            $row['amount'] = number_format(( $row['amount'] / 1000), 2) . 'K';
        }
        $excel_result[] = [
                    date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour')),
                    $row['transaction_no'],
                    $type_name  ,
                    $row['from_username'],
                    $isAgent_to_Agent ? $row['to_username'] : "-",
                    $isAgent_to_Agent ? "-" :  $row['member_username'],
                    $row['before_credit'],
                    $row['amount'],
                    $status_text,
                    $row['remark']
        ];
    }

    return excel($title, $header, $excel_result, $start, $end);
}

function sr14()
{
    global $lang;
    $title = $lang["menu_report_sr14"];
    $header  = [
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 25],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['before_point'], 'width' => 20],
        ['title' => $lang['change_point'], 'width' => 20],
        ['title' => $lang['moment_point'], 'width' => 15],
        ['title' => $lang['type'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 15],
        ['title' => $lang['memo'], 'width' => 15],
    ];

    $start = $_REQUEST['start_time'];
    $end = $_REQUEST['end_time'];

    $filter = array();
    $filter['member_username'] = $_REQUEST['member_username'];
    $filter['page'] = 1;
    $filter['per_page'] = 10000;
    $filter['start_time'] = $start;
    $filter['end_time'] = $end;

    $record = curlPost('api/report/sr14', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $type_name = $lang['transaction_type.' . $row['type']];
        $status_text = ($row['status'] == 1000) ? $lang['status_1'] : $lang['status_0'];
        $memo = ($row['type'] == 1 || $row['type'] == 2) ? $row['vendor_name'] : $row['remark'];
        if($_SESSION['toggle_thousands'] == 0) {
            $row['point'] = number_format($row['point'], 2);
            $row['balance'] = number_format($row['balance'], 2);
        } else {
            $row['point'] = number_format(( $row['point'] / 1000), 2) . 'K';
            $row['balance'] = number_format(( $row['balance'] / 1000), 2) . 'K';
        }

        $excel_result[] = [
            date('Y-m-d H:i:s', strtotime($row['created_at'] . '+8hour')),
            $row['agent_username'],
            $row['member_username'],
            $row['before_balance'],
            $row['point'],
            $row['balance'],
            $type_name,
            $status_text,
            $memo
        ];
    }
    return excel($title, $header, $excel_result, $start, $end);
}

function sr15_excel()
{
    global $lang;
    //取對應語言包
    $translates = curlPost('api/translate');
    $lab_jackpot = isset($translates['data']['3004']) ? $translates['data']['3004'] : 'Jackpot';
    $lab_cost = isset($translates['data']['3008']) ? $translates['data']['3008'] : 'Cost';

    $title = $lang["menu_report_sr15"];

    $header = [
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['unlimit_parent_member'], 'width' => 15],
        ['title' => $lang['total_bet'], 'width' => 20],
        ['title' => $lang['total_winlose'], 'width' => 20],
        ['title' => $lang['commission'], 'width' => 20],
        ['title' => $lang['jackpot_event'], 'width' => 20],
    ];

    if ($_REQUEST['cost_visible'] == 1) {
        $header = array_merge(
            $header,
            [['title' => $lab_cost, 'width' => 20]]
        );
    }
    $header = array_merge(
        $header,
        [
            ['title' => $lang['v_jackpot'], 'width' => 20],
            ['title' => $lang['total_score'], 'width' => 20]
        ]
    );

    $start = $_REQUEST['start_time'];
    $end = $_REQUEST['end_time'];

    $_REQUEST["per_page"] = 2000;

    $record = curlPost('api/report/sr15', $_REQUEST);
    $excel_result = array();
    foreach ($record['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format($row['total_bet'], 2);
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format($row['win_lose'], 2);
            }
            $row['commission'] = number_format($row['commission'], 2);
            $row['jackpot_event'] = number_format($row['jackpot_event'], 2);
            $row['v_jackpot'] = number_format($row['v_jackpot'], 2);
            $row['total_score'] = number_format($row['total_score'], 2);
        } else {
            if(is_numeric($row['total_bet'])) {
                $row['total_bet'] = number_format(( $row['total_bet'] / 1000), 2) . 'K';
            }
            if(is_numeric($row['win_lose'])) {
                $row['win_lose'] = number_format(( $row['win_lose'] / 1000), 2) . 'K';
            }
            $row['commission'] = number_format(( $row['commission'] / 1000), 2) . 'K';
            $row['jackpot_event'] = number_format(( $row['jackpot_event'] / 1000), 2) . 'K';
            $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 2) . 'K';
            $row['total_score'] = number_format(( $row['total_score'] / 1000), 2) . 'K';
        }
        $tmp = [
            $row['username'],
            $row['parent_username'],
            (($row['vendor_id'] == 36) ? '--' : $row['total_bet']),
            (($row['vendor_id'] == 36) ? '--' : $row['win_lose']),
            $row['commission'],
            $row['jackpot_event']
        ];
        if ($_REQUEST['cost_visible'] == 1) {
            if($_SESSION['toggle_thousands'] == 0) {
                $row['cost'] = number_format($row['cost'], 2);
            } else {
                $row['cost'] = number_format(( $row['cost'] / 1000), 2) . 'K';
            }
            $tmp = array_merge($tmp, [$row['cost']]);
        }
        $tmp = array_merge(
            $tmp,
            [
                $row['v_jackpot'],
                $row['total_score'],
            ]
        );
        $excel_result[] = $tmp;
    }
    
    return excel($title, $header, $excel_result, $start, $end);
}

function sr16()
{
    global $lang;
    $title = $lang["menu_report_sr16"];
    $header  = [
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 25],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['before_point'], 'width' => 20],
        ['title' => $lang['change_point'], 'width' => 20],
        ['title' => $lang['moment_point'], 'width' => 15],
        ['title' => $lang['type'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 15],
        ['title' => $lang['memo'], 'width' => 15],
    ];

    $start = $_REQUEST['start_time'];
    $end = $_REQUEST['end_time'];

    $filter = array();
    $filter['member_username'] = $_REQUEST['member_username'];
    $filter['page'] = 1;
    $filter['per_page'] = 10000;
    $filter['start_time'] = $start;
    $filter['end_time'] = $end;

    $record = curlPost('api/report/sr14', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $type_name = $lang['transaction_type.' . $row['type']];
        $status_text = ($row['status'] == 1000) ? $lang['status_1'] : $lang['status_0'];
        $memo = ($row['type'] == 1 || $row['type'] == 2) ? $row['vendor_name'] : $row['remark'];

        $excel_result[] = [
            date('Y-m-d H:i:s', strtotime($row['created_at'] . '+8hour')),
            $row['agent_username'],
            $row['member_username'],
            $row['before_balance'],
            $row['point'],
            $row['balance'],
            $type_name,
            $status_text,
            $memo
        ];
    }
    return excel($title, $header, $excel_result, $start, $end);
}

function sr16Period()
{
    global $lang;
    $title = $lang['menu_report_sr16'] . '-' . $lang["menu_sr16_period"];
    $header  = [
        ['title' => $lang['period'], 'width' => 20],
        ['title' => $lang['winning_numbers'], 'width' => 25],
        ['title' => $lang['total_number_of_pots'], 'width' => 15],
        ['title' => $lang['number_of_tickets_issued'], 'width' => 20],
        ['title' => $lang['bonus'], 'width' => 20],
        ['title' => $lang['ultimate_pot'], 'width' => 20],
        ['title' => $lang['first_prize'], 'width' => 20],
        ['title' => $lang['second_prize'], 'width' => 20],
        ['title' => $lang['third_prize'], 'width' => 20],
        ['title' => $lang['fourth_prize'], 'width' => 20],
    ];

    $filter = array();
    $filter['period_id'] = $_REQUEST['period_id'];
    $filter['page'] = 1;
    $filter['per_page'] = 60000;

    $record = curlPost('api/v_jackpot/period', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $period = str_replace('{N}', $row['no'], $lang['periods']);
        if($_SESSION['toggle_thousands'] == 0) {
            $row['v_jackpot'] = number_format($row['v_jackpot'], 2);
            $row['point'] = number_format($row['point'], 2);
        } else {
            $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 2) . 'K';
            $row['point'] = number_format(( $row['point'] / 1000), 2) . 'K';
        }

        $excel_result[] = [
            $period,
            $row['draw_number'],
            $row['v_jackpot'],
            number_format($row['tickets'], 0),
            $row['point'],
            $row['reward_item_1'],
            $row['reward_item_2'],
            $row['reward_item_3'],
            $row['reward_item_4'],
            $row['reward_item_5']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr16Member()
{
    global $lang;
    $title = $lang['menu_report_sr16'] . '-' . $lang["menu_sr16_member_search"];
    $header  = [
        ['title' => $lang['agent_account'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 25],
        ['title' => $lang['period'], 'width' => 15],
        ['title' => $lang['lottery_ticket_no'], 'width' => 20],
        ['title' => $lang['awards'], 'width' => 20],
        ['title' => $lang['bonus'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 15],
        ['title' => $lang['exchange_time'], 'width' => 15],
        ['title' => $lang['time'], 'width' => 15],
    ];

    $filter = array();
    if ($_REQUEST['type'] == 1) {
        $filter['agent_username'] = isset($_REQUEST['username']) ? $_REQUEST['username'] : 0;
    } else {
        $filter['member_username'] = isset($_REQUEST['username']) ? $_REQUEST['username'] : 0;
    }
    $filter['period_id'] = $_REQUEST['period_id'];
    $filter['page'] = 1;
    $filter['per_page'] = 60000;

    $record = curlPost('api/v_jackpot/member', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $prize_text = '';
        if ($row['lucky_draw_status'] == 0) {
            $prize_text = $lang['no_draw'];
        } else {
            switch ($row['reward_item_id']) {
                case 1:
                    $prize_text = $lang['ultimate_pot'];
                    break;
                case 2:
                    $prize_text = $lang['first_prize'];
                    break;
                case 3:
                    $prize_text = $lang['second_prize'];
                    break;
                case 4:
                    $prize_text = $lang['third_prize'];
                    break;
                case 5:
                    $prize_text = $lang['fourth_prize'];
                    break;
                default:
                    $prize_text = $lang['not_prize'];
                    break;
            }
        }
        $point_text = ($row['reward_item_id'] == 0) ? '' : number_format($row['point'], 2);
        if($_SESSION['toggle_thousands'] == 0) {
            $point_text = ($row['reward_item_id'] == 0) ? '' : number_format($row['point'], 2);
        } else {
            $point_text = ($row['reward_item_id'] == 0) ? '' : number_format(( $row['point'] / 1000), 2) . 'K';
        }
        $status_text = ($row['reward_item_id'] == 0) ? '' : (($row['status'] == 0) ? $lang['not_withdrawn'] : $lang['withdrawn']);

        $excel_result[] = [
            $row['agent_username'],
            $row['member_username'],
            str_replace('{N}', $row['no'], $lang['periods']),
            $row['ticket_number'],
            $prize_text,
            $point_text,
            $status_text,
            $row['time'],
            $row['created_at']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr16Ticket()
{
    global $lang;
    $title = $lang['menu_report_sr16'] . '-' . $lang["menu_sr16_ticket_search"];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['period'], 'width' => 20],
        ['title' => $lang['lottery_ticket_no'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 20],
    ];

    $filter = array();
    $filter['ticket_number'] = $_REQUEST['ticket_number'];
    $filter['period_id'] = $_REQUEST['period_id'];
    $filter['page'] = 1;
    $filter['per_page'] = 60000;

    $record = curlPost('api/v_jackpot/ticket', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $period = str_replace('{N}', $row['no'], $lang['periods']);

        $excel_result[] = [
            $row['id'],
            $period,
            $row['ticket_number'],
            $row['agent_username'],
            $row['member_username']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr16WinningList()
{
    global $lang;
    $title = $lang['menu_report_sr16'] . '-' . $lang["menu_sr16_period"] . ' ' . $lang["winning_list"];
    $header  = [
        ['title' => $lang['agent_name'], 'width' => 20],
        ['title' => $lang['member_name'], 'width' => 20],
        ['title' => $lang['lottery_ticket_no'], 'width' => 20],
        ['title' => $lang['bonus'], 'width' => 20],
    ];

    $filter = array();
    $filter['period_id'] = $_REQUEST['period_id'];
    $record = curlPost('api/v_jackpot/winningList', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $excel_result[] = [
            $row['agent_username'],
            $row['member_username'],
            $row['ticket_number'],
            $row['point']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr17Period()
{
    global $lang;
    $title = $lang['menu_report_sr17'] . '-' . $lang["menu_sr17_period"];
    $header  = [
        ['title' => $lang['period'], 'width' => 20],
        ['title' => $lang['winning_numbers'] . '-' . $lang['first_prize'], 'width' => 15],
        ['title' => $lang['winning_numbers'] . '-' . $lang['second_prize'], 'width' => 15],
        ['title' => $lang['winning_numbers'] . '-' . $lang['third_prize'], 'width' => 15],
        ['title' => $lang['winning_numbers'] . '-' . $lang['special_prize'], 'width' => 50],
        ['title' => $lang['winning_numbers'] . '-' . $lang['consolation_prize'], 'width' => 50],
        ['title' => $lang['total_number_of_pots'], 'width' => 15],
        ['title' => $lang['number_of_tickets_issued'], 'width' => 20],
        ['title' => $lang['bonus'], 'width' => 20],
        ['title' => $lang['first_prize'], 'width' => 20],
        ['title' => $lang['second_prize'], 'width' => 20],
        ['title' => $lang['third_prize'], 'width' => 20],
        ['title' => $lang['special_prize'], 'width' => 20],
        ['title' => $lang['consolation_prize'], 'width' => 20],
    ];

    $filter = array();
    $filter['period_id'] = $_REQUEST['period_id'];
    $filter['page'] = 1;
    $filter['per_page'] = 60000;

    $record = curlPost('api/w_jackpot/period', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $period = str_replace('{N}', $row['no'], $lang['periods']);
        if($_SESSION['toggle_thousands'] == 0) {
            $row['v_jackpot'] = number_format($row['v_jackpot'], 2);
            $row['point'] = number_format($row['point'], 2);
        } else {
            $row['v_jackpot'] = number_format(( $row['v_jackpot'] / 1000), 2) . 'K';
            $row['point'] = number_format(( $row['point'] / 1000), 2) . 'K';
        }

        $excel_result[] = [
            $period,
            $row['draw_number'],
            $row['draw_number_2'],
            $row['draw_number_3'],
            $row['draw_number_4'],
            $row['draw_number_5'],
            $row['v_jackpot'],
            number_format($row['tickets'], 0),
            $row['point'],
            $row['reward_item_1'],
            $row['reward_item_2'],
            $row['reward_item_3'],
            $row['reward_item_4'],
            $row['reward_item_5']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr17Member()
{
    global $lang;
    $title = $lang['menu_report_sr17'] . '-' . $lang["menu_sr17_member_search"];
    $header  = [
        ['title' => $lang['agent_account'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 25],
        ['title' => $lang['period'], 'width' => 15],
        ['title' => $lang['lottery_ticket_no'], 'width' => 20],
        ['title' => $lang['awards'], 'width' => 20],
        ['title' => $lang['bonus'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 15],
        ['title' => $lang['exchange_time'], 'width' => 15],
        ['title' => $lang['time'], 'width' => 15],
    ];

    $filter = array();
    if ($_REQUEST['type'] == 1) {
        $filter['agent_username'] = isset($_REQUEST['username']) ? $_REQUEST['username'] : 0;
    } else {
        $filter['member_username'] = isset($_REQUEST['username']) ? $_REQUEST['username'] : 0;
    }
    $filter['period_id'] = $_REQUEST['period_id'];
    $filter['page'] = 1;
    $filter['per_page'] = 60000;

    $record = curlPost('api/w_jackpot/member', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $prize_text = '';
        if ($row['lucky_draw_status'] == 0) {
            $prize_text = $lang['no_draw'];
        } else {
            switch ($row['reward_item_id']) {
                case 1:
                    $prize_text = $lang['first_prize'];
                    break;
                case 2:
                    $prize_text = $lang['second_prize'];
                    break;
                case 3:
                    $prize_text = $lang['third_prize'];
                    break;
                case 4:
                    $prize_text = $lang['special_prize'];
                    break;
                case 5:
                    $prize_text = $lang['consolation_prize'];
                    break;
                default:
                    $prize_text = $lang['not_prize'];
                    break;
            }
        }
        $point_text = ($row['reward_item_id'] == 0) ? '' : number_format($row['point'], 2);
        if($_SESSION['toggle_thousands'] == 0) {
            $point_text = ($row['reward_item_id'] == 0) ? '' : number_format($row['point'], 2);
        } else {
            $point_text = ($row['reward_item_id'] == 0) ? '' : number_format(( $row['point'] / 1000), 2) . 'K';
        }
        $status_text = ($row['reward_item_id'] == 0) ? '' : (($row['status'] == 0) ? $lang['not_withdrawn'] : $lang['withdrawn']);

        $excel_result[] = [
            $row['agent_username'],
            $row['member_username'],
            str_replace('{N}', $row['no'], $lang['periods']),
            $row['ticket_number'],
            $prize_text,
            $point_text,
            $status_text,
            $row['time'],
            $row['created_at']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr17Ticket()
{
    global $lang;
    $title = $lang['menu_report_sr17'] . '-' . $lang["menu_sr17_ticket_search"];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['period'], 'width' => 20],
        ['title' => $lang['lottery_ticket_no'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 20],
    ];

    $filter = array();
    $filter['ticket_number'] = $_REQUEST['ticket_number'];
    $filter['period_id'] = $_REQUEST['period_id'];
    $filter['page'] = 1;
    $filter['per_page'] = 60000;

    $record = curlPost('api/w_jackpot/ticket', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $period = str_replace('{N}', $row['no'], $lang['periods']);

        $excel_result[] = [
            $row['id'],
            $period,
            $row['ticket_number'],
            $row['agent_username'],
            $row['member_username']
        ];
    }
    return excel($title, $header, $excel_result);
}

function sr17WinningList()
{
    global $lang;
    $title = $lang['menu_report_sr17'] . '-' . $lang["menu_sr17_period"] . ' ' . $lang["winning_list"];
    $header  = [
        ['title' => $lang['agent_name'], 'width' => 20],
        ['title' => $lang['member_name'], 'width' => 20],
        ['title' => $lang['lottery_ticket_no'], 'width' => 20],
        ['title' => $lang['bonus'], 'width' => 20],
    ];

    $filter = array();
    $filter['period_id'] = $_REQUEST['period_id'];
    $record = curlPost('api/w_jackpot/winningList', $filter);
    $excel_result = array();

    foreach ($record['data'] as $row) {
        $excel_result[] = [
            $row['agent_username'],
            $row['member_username'],
            ' ' . $row['ticket_number'],
            $row['point']
        ];
    }
    return excel($title, $header, $excel_result);
}

function transactionList()
{
    global $lang;
    $title = $lang['sr02_1'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['ordernum'], 'width' => 40],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['deposit'] . '/' . $lang['withdrawal'], 'width' => 10],
        ['title' => $lang['agent_account_to'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 20],
        ['title' => $lang['change_point'], 'width' => 15],
        ['title' => $lang['moment_point'], 'width' => 15],
        ['title' => $lang['source'], 'width' => 15],
        ['title' => $lang['execution_account'], 'width' => 20],
        ['title' => $lang['order_status'], 'width' => 10],
        ['title' => $lang['memo'], 'width' => 20],
    ];
    $data = array();
    if ($_REQUEST['type'] == 1) {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }
    
    $data['cash_flow_id'] = $_GET['cash_flow_id'] ? $_GET['cash_flow_id'] : "";
    $data['source'] = $_REQUEST['source'] ? $_REQUEST['source'] : 0;
    $data['filter'] = $_GET['filter'] ? $_GET['filter'] : 1;
    $data['deposit_to'] = $_GET['deposit_to'] ? $_GET['deposit_to'] : 0;
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':59';
    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $data['page'] = 1;
    $data['per_page'] = 100000;
    $member = curlPost('api/report/transactionList', $data);
    $excel_result = array();
    foreach ($member['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['point'] = number_format($row['point'], 2);
            $row['balance'] = number_format($row['balance'], 2);
        } else {
            $row['point'] = number_format(($row['point'] / 1000), 2) . 'K';
            $row['balance'] = number_format(($row['balance'] / 1000), 2) . 'K';
        }
        $excel_result[] = [
            'num' => $row['id'],
            'sr02_order_num' => ' ' . $row['transaction_no'] . PHP_EOL . $row['third_party_order_no'],
            'date' => $row['created_at'],
            'agent_account' => $row['agent_username'],
            'deposit' => $lang['transaction_type.'.$row['type']],
            'agent_account_to' => $row['to_agent_username'],
            'member_account' => $row['member_username'],
            'change_point' => $row['point'],
            'moment_point' => $row['balance'],
            'source' => $row['source'],
            'execution_account' => $row['operator_username'],
            'order_status' => $row['status'],
            'memo' => $row['remark']
        ];
    }
    return excel($title, $header, $excel_result, $start, $end);
}

function transactionListNew()
{
    global $lang;
    $title = $lang['ssr02'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['ordernum'], 'width' => 40],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['deposit'] . '/' . $lang['withdrawal'], 'width' => 10],
        ['title' => $lang['agent_account_to'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 20],
        ['title' => $lang['change_point'], 'width' => 15],
        ['title' => $lang['moment_point'], 'width' => 15],
        ['title' => $lang['source'], 'width' => 15],
        ['title' => $lang['execution_account'], 'width' => 20],
        ['title' => $lang['order_status'], 'width' => 10],
        ['title' => $lang['memo'], 'width' => 20],
    ];
    $data = array();
    if ($_REQUEST['type'] == 1) {
        $data['agent_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } elseif ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }

    $data['cash_flow_id'] = $_GET['cash_flow_id'] ? $_GET['cash_flow_id'] : "";
    $data['source'] = $_REQUEST['source'] ? $_REQUEST['source'] : 0;
    $data['filter'] = $_GET['filter'] ? $_GET['filter'] : 1;
    $data['deposit_to'] = $_GET['deposit_to'] ? $_GET['deposit_to'] : 0;
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':59';
    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $data['page'] = 1;
    $data['per_page'] = 100000;
    $member = curlPost('api/report/transactionListNew', $data);
    $excel_result = array();
    foreach ($member['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['point'] = number_format($row['point'], 2);
            $row['balance'] = number_format($row['balance'], 2);
        } else {
            $row['point'] = number_format(($row['point'] / 1000), 2) . 'K';
            $row['balance'] = number_format(($row['balance'] / 1000), 2) . 'K';
        }
        $excel_result[] = [
            'num' => $row['id'],
            'sr02_order_num' => ' ' . $row['transaction_no'] . PHP_EOL . $row['third_party_order_no'],
            'date' => $row['created_at'],
            'agent_account' => $row['agent_username'],
            'deposit' => $lang['transaction_type.'.$row['type']],
            'agent_account_to' => $row['to_agent_username'],
            'member_account' => $row['member_username'],
            'change_point' => $row['point'],
            'moment_point' => $row['balance'],
            'source' => $row['source'],
            'execution_account' => $row['operator_username'],
            'order_status' => $row['status'],
            'memo' => $row['remark']
        ];
    }
    return excel($title, $header, $excel_result, $start, $end);
}

function prizeReport()
{
    global $lang;
    $title = '活動獎勵領取記錄';
    $header  = [
        ['title' => $lang['exchange_time'], 'width' => 20],
        ['title' => $lang['event_name'], 'width' => 40],
        ['title' => $lang['member_account'], 'width' => 20],
        ['title' => $lang['agent_previous'], 'width' => 20],
        ['title' => $lang['reward_name'], 'width' => 30],
        ['title' => $lang['point'], 'width' => 15],
    ];
    $data = array();
    if ($_GET['type'] == 1 && $_GET['username'] != '') {
        $data['user_username'] = $_GET['username'];
    } elseif ($_GET['type'] == 2 && $_GET['username'] != '') {
        $data['agent_username'] = $_GET['username'];
    } elseif ($_GET['type'] == 3 && $_GET['username'] != '') {
        $data['member_username'] = $_GET['username'];
    }
    $data['event_id'] = $_REQUEST['event_id'];
    $data['page'] = 1;
    $data['per_page'] = 100000;
    $member = curlPost('api/report/prizeReport', $data);
    return excel($title, $header, $member['data']);
}

function excel($title, $header, $data, $start = '', $end = '')
{
    ini_set('memory_limit', '128M');
    global $lang;
    require_once "../class/PHPExcel.php";
    require_once "../class/PHPExcel/Worksheet/Drawing.php";
    // Create new PHPExcel object
    $objPHPExcel = new PHPExcel();
    // Set properties
    $objPHPExcel->getProperties()->setCreator("system")
        ->setLastModifiedBy("system")
        ->setTitle("Report")
        ->setSubject("Report")
        ->setDescription("Report")
        ->setKeywords("Report")
        ->setCategory("Report");

    $range = range("A", "Z");
    // 设置宽度
    $x = 0;
    foreach ($header as $row) {
        $objPHPExcel->getActiveSheet()->getColumnDimension($range[$x])->setWidth($row['width']);
        $x++;
    }

    $last_column = $range[$x - 1];
    // 设置行高度
    $objPHPExcel->getActiveSheet()->getRowDimension('1')->setRowHeight(22);
    $objPHPExcel->getActiveSheet()->getRowDimension('2')->setRowHeight(20);

    // 字体和样式
    $objPHPExcel->getActiveSheet()->getDefaultStyle()->getFont()->setSize(11);
    $objPHPExcel->getActiveSheet()->getStyle('A2:' . $last_column . '2')->getFont()->setBold(true);
    $objPHPExcel->getActiveSheet()
        ->getStyle('A1')
        ->getFont()
        ->setBold(true);

    // 设置水平居中
    $objPHPExcel->getActiveSheet()
        ->getStyle('A1')
        ->getAlignment()
        ->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
    $objPHPExcel->getActiveSheet()
        ->getStyle('A:' . $last_column)
        ->getAlignment()
        ->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);

    //設置框線
    // $objPHPExcel->getActiveSheet()->getStyle('A2:' . $last_column . '2')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
    // $objPHPExcel->getActiveSheet()->getStyle('A2:' . $last_column . '2')->getBorders()->getAllBorders()->setBorderStyle(PHPExcel_Style_Border::BORDER_THIN);

    //  合并
    $objPHPExcel->getActiveSheet()->mergeCells('A1:' . $last_column . '1');

    // 表头
    $objPHPExcel->setActiveSheetIndex(0)->setCellValue('A1', $title);
    $i = 2;
    if ($start != '') {
        //  合并
        $objPHPExcel->getActiveSheet()->mergeCells('A2:' . $last_column . '2');
        $objPHPExcel->setActiveSheetIndex(0)
            ->setCellValue('A2', $lang['report_between'] . '：' . $start . ' ~ ' . $end);
        $i = 3;
    }
    $x = 0;
    foreach ($header as $row) {
        $objPHPExcel->setActiveSheetIndex(0)
            ->setCellValue($range[$x] . $i, $row['title']);
        $x++;
    }
    $column = count($header);
    $i++;
    foreach ($data as $row) {
        $x = 0;
        foreach ($row as $key => $value) {
            //多餘的資料會放在array後面
            if ($x < $column) {
                if ($key == 'transaction_no') {
                    $objPHPExcel->getActiveSheet()
                        ->setCellValueExplicit($range[$x] . $i, $value, PHPExcel_Cell_DataType::TYPE_STRING);
                } elseif ($key == 'sr02_order_num') {
                    $objPHPExcel->getActiveSheet()->setCellValue($range[$x] . $i, $value);
                    $objPHPExcel->getActiveSheet()->getStyle($range[$x] . $i)->getAlignment()->setWrapText(true);
                } else {
                    $objPHPExcel->getActiveSheet(0)
                        ->setCellValue($range[$x] . $i, $value);
                }
                $x++;
            } else {
                break;
            }
        }
        $i++;
    }
    // Set active sheet index to the first sheet, so Excel opens this as the first sheet
    $objPHPExcel->setActiveSheetIndex(0);
    // 输出
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="' . $title . date("Y-m-d") . '.xlsx"');
    header('Cache-Control: max-age=0');

    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');

    $objWriter->save('php://output');
    die();
}