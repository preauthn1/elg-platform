<?php
require_once('../_init.php');
switch ($_REQUEST['action']) {
    case "withdrawal":
        echo withdrawal();
        break;

    default:
        break;
}

function withdrawal()
{
    global $lang;
    $title = $lang['menu_withdrawal_order_review'];
    $header = [
        ['title' => $lang['date'], 'width' => 20],
        ['title' => $lang['ordernum'], 'width' => 10],
        ['title' => $lang['third_order_no'], 'width' => 30],
        ['title' => $lang['bank_name'], 'width' => 20],
        ['title' => $lang['bank_code'], 'width' => 20],
        ['title' => $lang['account_name'], 'width' => 20],
        ['title' => $lang['account_no'], 'width' => 20],
        ['title' => $lang['member_account'], 'width' => 15],
        ['title' => $lang['amount'], 'width' => 10],
        ['title' => $lang['memo'], 'width' => 20],
        ['title' => $lang['order_status'], 'width' => 15],
    ];
    if ($_GET['type'] == 1) {
        $data['order_no'] = $_GET['username'];
    } else if ($_GET['type'] == 2) {
        $data['member_username'] = $_GET['username'];
    } else if ($_GET['type'] == 3) {
        $data['account_no'] = $_GET['username'];
    } else if ($_GET['type'] == 4) {
        $data['transaction_no'] = $_GET['username'];
    }
    // 狀態
    if ($_GET['status'] != '' && isset($_GET['status'])) {
        $data['status'] = $_GET['status'];
    }

    $data['start_time'] = isset($_GET['start_time']) ? $_GET['start_time'] : date('Y-m-d 00:00:00', strtotime('-16hour'));
    $data['end_time'] = isset($_GET['end_time']) ? $_GET['end_time'] : date('Y-m-d 23:59:59', strtotime('+8hour'));

    $data['page'] = $_GET['page'] ? $_GET['page'] : 1;
    $data['per_page'] = 100000;
    $record = curlPost('api/withdrawal/list', $data);
    $result = array();
    $a = 0;
    foreach ($record['data'] as $row) {
        if($_SESSION['toggle_thousands'] == 0) {
            $row['amount'] = number_format($row['amount'], 2);
        } else {
            $row['amount'] = number_format(($row['amount'] / 1000), 2) . 'K';
        }
        $result[$a]['date'] = $row['create_at'];
        $result[$a]['ordernum'] = $row['order_id'];

        $result[$a]['third_order_no'] = ($row['transaction_no'] != '')
            ? str_replace(',', PHP_EOL, $row['transaction_no'])
            : '';
        $result[$a]['bank_name'] = $row['bank_name'];
        $result[$a]['bank_code'] = $row['bank_code'];
        $result[$a]['account_name'] = $row['account_name'];
        $result[$a]['account_no'] = $row['account_no'];
        $result[$a]['member_account'] = $row['username'];
        $result[$a]['amount'] = $row['amount'];
        $result[$a]['memo'] = $row['remark'];
        $result[$a]['order_status'] = $lang["withdrawal_status_".$row['status']];
        
        $a++;
    }
    return excel($title, $header, $result);
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
                if (in_array($key, ['ordernum', 'account_no'])) {
                    $objPHPExcel->getActiveSheet()->setCellValueExplicit($range[$x] . $i, $value, PHPExcel_Cell_DataType::TYPE_STRING);
                } elseif ($key == 'third_order_no') {
                    $objPHPExcel->getActiveSheet()->setCellValue($range[$x] . $i, $value);
                    $objPHPExcel->getActiveSheet()->getStyle($range[$x] . $i)->getAlignment()->setWrapText(true);
                } else {
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