<?php
require_once('./_init.php');

switch ($_REQUEST['action']) {
    case "excel_agent_operation_logs":
        echo excel_agent_operation_logs();
        break;

    case "excel_orders":
        echo excel_orders();
        break;

    default:
        break;
}

function excel_orders()
{
    global $lang;
    $title = $lang['menu_order'];
    $header  = [
        ['title' => $lang['ordernum'], 'width' => 20],
        ['title' => $lang['ordernum'].'('.$lang['deposit_vendor_name'].')' , 'width' => 20],
        ['title' => $lang['time'], 'width' => 30],
        ['title' => $lang['deposit_vendor_name'], 'width' => 20],
        ['title' => $lang['deposit_order_type_name'], 'width' => 20],
        ['title' => $lang['agent'], 'width' => 20],
        ['title' => $lang['member'], 'width' => 20],
        ['title' => $lang['amount'], 'width' => 20],
        ['title' => $lang['point'], 'width' => 20],
        ['title' => $lang['order_status'], 'width' => 20],
    ];

    $data = array();
    parse_str($_SERVER['QUERY_STRING'], $data);
    
    $data['page'] = 1;
    $data['per_page'] = 5000;

    $record = curlPost('api/order/list', $data);
    $result = array();
    $a = 0;

    foreach ($record['data'] as $row) {
        $result[$a]['order_no'] = $row["order_no"];
        $result[$a]['third_party_order_no'] = $row["third_party_order_no"];
        $result[$a]['time'] = date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour'));

        $result[$a]['cf_name'] = $row["cf_name"];
        $result[$a]['order_type_name'] = $row["order_type_name"];
        $result[$a]['agent_username'] = $row["agent_username"];
        $result[$a]['member_username'] = $row["member_username"];

        if ($_SESSION['toggle_thousands'] == 0) {
            $result[$a]['amount'] = number_format($row['amount'], 2);
            $result[$a]['point'] = number_format($row['point'], 2);
        } else {
            $result[$a]['amount'] = number_format(($row['amount'] / 1000), 2) . 'K';
            $result[$a]['point'] = number_format(($row['point'] / 1000), 2) . 'K';
        }
        $result[$a]['status_name'] = $row["status_name"];
        $a++;
    }

    return excel($title, $header, $result);
}


function excel_agent_operation_logs()
{
    global $lang;
    $title = $lang['menu_agent_operate_logs'];
    $header  = [
        ['title' => $lang['time'], 'width' => 30],
        ['title' => $lang['type'], 'width' => 30],
        ['title' => $lang['operator'], 'width' => 30],
        ['title' => $lang['update_target'], 'width' => 30],
        ['title' => $lang['ip'], 'width' => 30]
    ];

    $data = array();
    parse_str($_SERVER['QUERY_STRING'], $data);
    
    $data['page'] = 1;
    $data['per_page'] = 5000;

    $record = curlPost('api/agent/operate/logs', $data);
    $result = array();
    $a = 0;

    foreach ($record['data'] as $row) {
        $result[$a]['time'] = date('Y-m-d H:i:s', strtotime($row['created_at']. '+8hour'));
        $result[$a]['type'] = $row["type_name"];
        $result[$a]['operator'] = $row["operator"];
        $result[$a]['update_target'] = $row["targetUserName"];
        $result[$a]['ip'] = $row["ip"];
        $a++;
    }

    return excel($title, $header, $result);
}



function excel($title, $header, $data, $start = '', $end = '')
{
    global $lang;
    require_once "./class/PHPExcel.php";
    require_once "./class/PHPExcel/Worksheet/Drawing.php";
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
        $objPHPExcel->getActiveSheet()
            ->getColumnDimension($range[$x])
            ->setWidth($row['width']);
        $x++;
    }

    $last_column = $range[$x - 1];
    // 设置行高度
    $objPHPExcel->getActiveSheet()->getRowDimension('1')->setRowHeight(22);
    $objPHPExcel->getActiveSheet()->getRowDimension('2')->setRowHeight(20);

    // 字体和样式
    $objPHPExcel->getActiveSheet()
        ->getDefaultStyle()
        ->getFont()
        ->setSize(11);
    $objPHPExcel->getActiveSheet()
        ->getStyle('A2:' . $last_column . '2')
        ->getFont()
        ->setBold(true);
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
                //有些單號會被excel轉成 2.02103E+17 (被誤認成是很大的數字)
                if ($key == 'transaction_no' || $key == 'order_no' || $key == 'third_party_order_no') {
                    $objPHPExcel->getActiveSheet()
                        ->setCellValueExplicit($range[$x] . $i, $value, PHPExcel_Cell_DataType::TYPE_STRING);
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