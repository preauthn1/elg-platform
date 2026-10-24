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
    case "sr11":
        echo sr11('total');
        break;
    case "sr11_detail":
        echo sr11('detail');
        break;
    case "sr11_member":
        echo sr11Member();
        break;
    case "sr12":
        echo sr12();
        break;
    case "transactionList":
        echo transactionList();
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
    } else if ($_REQUEST['type'] == 2) {
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
        $result[] = [
            $row['member_username'],
            $row['total_in'],
            $row['total_out'],
            ($row['total_in'] - $row['total_out']),
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
        ['title' => $lang['source'], 'width' => 15],
        ['title' => $lang['execution_account'], 'width' => 20],
        ['title' => $lang['order_status'], 'width' => 10],
        ['title' => $lang['memo'], 'width' => 20],
    ];
    $data = array();
    if ($_REQUEST['type'] == 1) {
        $data['agent_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }
    $data['source'] = $_REQUEST['source'] ? $_REQUEST['source'] : 0;
    $start = $_REQUEST['start_date'];
    $end = $_REQUEST['end_date'];

    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $member = curlPost('api/report/sr05', $data);
    $result = array();
    foreach ($member['data'] as $row) {
        $row['type'] = ($row['type'] == 1 ? $lang['transferin'] : $lang['transferout']);
        $row['source'] = ($row['source'] == 1) ? $lang['cs'] : $lang['onlinepay'];
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
    } else if ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }
    $start = $_REQUEST['start_date'];
    $end = $_REQUEST['end_date'];

    $data['start_date'] = $start;
    $data['end_date'] = $end;
    $data['status'] = $_REQUEST['status'];
    $data['page'] = 1;
    $data['per_page'] = 10000;
    $member = curlPost('api/report/sr06', $data);
    $result = array();
    foreach ($member['data'] as $row) {
        $row['type'] = ($row['type'] == 1 ? '轉出' : '轉入');
        $row['point'] = ($row['type'] == 1) ? -$row['point'] : $row['point'];
        $row['status'] = ($row['status'] == 1000 ? $lang['success'] : $lang['failure']);
        $result[] = $row;
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
        ['title' => $lang['accept_account'], 'width' => 20],
        ['title' => $lang['send_account'], 'width' => 20],
        ['title' => $lang['point'], 'width' => 15],
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
    if($response->statusCode != 200) {
        exit();
    }
    $member = (array) $response->data;
    $result = array();
    $a = 0;
    foreach ($member['data'] as $row) {
        $row = (array) $row;
        $result[$a]['id'] = $row['id'];
        $result[$a]['created_at'] = $row['created_at'];
        $result[$a]['self_username'] = $row['self_username'];
        $result[$a]['other_username'] = $row['other_username'];
        $result[$a]['amount'] = $row['amount'];
        $arr_status = [$lang['failure'],$lang['unaccalimed'],$lang['success_received'],$lang['cancel'],$lang['expired']];
        $result[$a]['status'] = $arr_status[$row['status']];
        $a++;
    }
    return excel($title, $header, $result, $start_date, $end_date);
}

function sr11($type)
{
    global $lang;
    $title = $lang['sr11'];
    $header  = [
        ['title' => $lang['type'], 'width' => 15],
        ['title' => $lang['num'], 'width' => 15],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['agent_name'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['game_type'], 'width' => 10],
        ['title' => $lang['total_bet'], 'width' => 15],
        ['title' => $lang['total_winlose'], 'width' => 15],
        ['title' => $lang['win_jackpot'], 'width' => 15],
        ['title' => $lang['total_score'], 'width' => 15],
        ['title' => $lang['divided'], 'width' => 15],
        ['title' => $lang['divided_between'], 'width' => 15],
    ];
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':00';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    $data['game_type'] = $_REQUEST['game_type'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['user_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    if ($type == 'total') {
        $record = curlPost('api/report/platformReconciliationTotal', $data);
    } else if ($type == 'detail') {
        $data['page'] = 1;
        $data['per_page'] = 10000;
        $record = curlPost('api/report/platformReconciliationDetail', $data);
    }

    $result = array();
    $i = 1;
    foreach ($record['data'] as $row) {
        $result[] = [
            $lang[$row['type']],
            $i++,
            $row['agent_username'],
            $row['agent_nickname'],
            $row['vendor'],
            $row['game_type'],
            $row['total_bet'],
            $row['win_lose'],
            $row['lottery'],
            ($row['win_lose'] + $row['lottery']),
            $row['share_amount'],
            '-',
        ];
    }

    return excel($title, $header, $result, $start, $end);
}


function sr11Member()
{
    global $lang;
    $title = $lang['sr11_1'];
    $header  = [
        ['title' => $lang['num'], 'width' => 15],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['member_name'], 'width' => 15],
        ['title' => $lang['platform'], 'width' => 15],
        ['title' => $lang['game_type'], 'width' => 10],
        ['title' => $lang['total_bet'], 'width' => 15],
        ['title' => $lang['total_winlose'], 'width' => 15],
        ['title' => $lang['win_jackpot'], 'width' => 15],
        ['title' => $lang['total_score'], 'width' => 15],
    ];
    $start = $_REQUEST['start_date'] . ':00';
    $end = $_REQUEST['end_date'] . ':00';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    $data['game_type'] = $_REQUEST['game_type'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['user_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    }
    $data['start_time'] = $start;
    $data['end_time'] = $end;
    $record = curlPost('api/report/platformReconciliationMember', $data);

    $result = array();
    $i = 1;
    foreach ($record['data'] as $row) {
        $result[] = [
            $i++,
            $row['member_username'],
            $row['member_nickname'],
            $row['vendor'],
            $row['game_type'],
            $row['total_bet'],
            $row['win_lose'],
            $row['lottery'],
            ($row['win_lose'] + $row['lottery']),
        ];
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
        // ['title' => $lang['agent_name'], 'width' => 15],
        ['title' => $lang['game_name'], 'width' => 25],
        ['title' => $lang['bet'], 'width' => 15],
        ['title' => $lang['jackpot'], 'width' => 15],
        ['title' => $lang['winlose'], 'width' => 15],
        ['title' => $lang['ordernumber'], 'width' => 15],
        // ['title' => $lang['bet_time'], 'width' => 20],
        // ['title' => $lang['settle_time'], 'width' => 15],
        // ['title' => $lang['bet_status'], 'width' => 10],
        // ['title' => $lang['memo'], 'width' => 20],
    ];
    $start = $_REQUEST['start_time'] . ':00';
    $end = $_REQUEST['end_time'] . ':59';

    $data = array();
    $data['vendor_id'] = $_REQUEST['vendor_id'];
    if ($_REQUEST['type'] == 1 && $_REQUEST['username'] != '') {
        $data['agent_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 2 && $_REQUEST['username'] != '') {
        $data['member_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 3 && $_REQUEST['username'] != '') {
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
        // $row['bet_status'] = ($row['bet_status'] == 1) ? $lang['settlement'] : $lang['unsettlement'];
        $result[] = [
            $row['bet_time'],
            $row['agent_username'],
            $row['member_username'],
            $row['game_name'],
            $row['betAmount'],
            $row['lottery'],
            $row['win_lose'],
            $row['game_no']        
        ];
    }

    return excel($title, $header, $result, $start, $end);
}

function transactionList()
{
    global $lang;
    $title = $lang['sr02_1'];
    $header  = [
        ['title' => $lang['num'], 'width' => 10],
        ['title' => $lang['ordernum'], 'width' => 20],
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['deposit'] . '/' . $lang['withdrawal'], 'width' => 10],
        ['title' => $lang['member_account'], 'width' => 20],
        ['title' => $lang['member_nickname'], 'width' => 20],
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
    } else if ($_REQUEST['type'] == 2) {
        $data['member_username'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 3) {
        $data['member_nickname'] = $_REQUEST['username'];
    } else if ($_REQUEST['type'] == 4) {
        $data['transaction_no'] = $_REQUEST['username'];
    }
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
    return excel($title, $header, $member['data'], $start, $end);
}

function prizeReport()
{
    $title = '活動獎勵領取記錄';
    $header  = [
        ['title' => '兌換時間', 'width' => 20],
        ['title' => '活動名稱', 'width' => 40],
        ['title' => '會員帳號', 'width' => 20],
        ['title' => '上線代理', 'width' => 20],
        ['title' => '獎項名稱', 'width' => 30],
        ['title' => '點數', 'width' => 15],
    ];
    $data = array();
    if ($_GET['type'] == 1 && $_GET['username'] != '') {
        $data['user_username'] = $_GET['username'];
    } else if ($_GET['type'] == 2 && $_GET['username'] != '') {
        $data['agent_username'] = $_GET['username'];
    } else if ($_GET['type'] == 3 && $_GET['username'] != '') {
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
    $objPHPExcel->getActiveSheet()->getStyle('A1')->getFont()->setBold(true);


    // 设置水平居中
    $objPHPExcel->getActiveSheet()->getStyle('A1')->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
    $objPHPExcel->getActiveSheet()->getStyle('A:' . $last_column)->getAlignment()->setHorizontal(PHPExcel_Style_Alignment::HORIZONTAL_CENTER);

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
        $objPHPExcel->setActiveSheetIndex(0)->setCellValue('A2', $lang['report_between'] . '：' . $start . ' ~ ' . $end);
        $i = 3;
    }
    $x = 0;
    foreach ($header as $row) {
        $objPHPExcel->setActiveSheetIndex(0)->setCellValue($range[$x] . $i, $row['title']);
        $x++;
    }
    $column = count($header);
    $i++;
    foreach ($data as $row) {
        $x = 0;
        foreach ($row as $key => $value) {
            //多餘的資料會放在array後面
            if ($x < $column) {
                if($key == 'transaction_no'){
                    $objPHPExcel->getActiveSheet()->setCellValueExplicit($range[$x] . $i, $value, PHPExcel_Cell_DataType::TYPE_STRING);
                }else{
                    $objPHPExcel->getActiveSheet(0)->setCellValue($range[$x] . $i, $value);
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