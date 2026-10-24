<?php
require_once('../_init.php');
switch ($_REQUEST['action']) {
    case "member_account":
        echo member_account();
        break;
}

function member_account()
{
    global $lang;
    $title = $lang['member_account'];
    $header  = [
        ['title' => $lang['createdate'], 'width' => 15],
        ['title' => $lang['agent_account'], 'width' => 15],
        ['title' => $lang['username'], 'width' => 20],
        ['title' => $lang['name'], 'width' => 20],
        ['title' => $lang['id'], 'width' => 20],
        ['title' => $lang['balance'], 'width' => 15],
        ['title' => $lang['transfer'], 'width' => 20],
        ['title' => $lang['email'], 'width' => 20],
        ['title' => $lang['tel'], 'width' => 25],
        ['title' => $lang['status_certification'], 'width' => 15],
        ['title' => $lang['status'], 'width' => 10],
    ];
    if ($_GET['agent_id'] != '' && isset($_GET['agent_id'])) {
        $data['agent_id'] = $_GET['agent_id'];
    }
    // username
    if ($_GET['username'] != '' && isset($_GET['username'])) {
        $data['username'] = $_GET['username'];
    }
    // email
    if ($_GET['email'] != '' && isset($_GET['email'])) {
        $data['email'] = $_GET['email'];
    }
    // 狀態
    if ($_GET['status'] != '' && isset($_GET['status'])) {
        $data['status'] = $_GET['status'];
    }
    // 認證狀態
    if ($_GET['certification'] != '' && isset($_GET['certification'])) {
        $data['certification'] = $_GET['certification'];
    }
    $data['page'] = $_GET['page'] ? $_GET['page'] : 1;
    $data['per_page'] = 100000;
    $record = curlPost('api/member/list', $data);
    $result = array();
    $a = 0;
    foreach ($record['data'] as $row) {
        $result[$a]['created_at'] = $row['created_at'];
        $result[$a]['agent_username'] = $row['agent_username'];
        $result[$a]['username'] = $row['username'];
        $result[$a]['name'] = $row['first_name'] . ' ' . $row['last_name'];
        $result[$a]['id'] = $lang['id'] . ' ' . $row['id'];
        $result[$a]['balance'] = $row['balance'];
        if ($row['balance'] != -1){
            $result[$a]['transfer'] = $lang['deposit'].' '.$lang['withdrawal'];
        } else {
            $result[$a]['transfer'] = '-';
        }
        $result[$a]['email'] = $row['email'];
        $result[$a]['mobile'] = $row['mobile'];
        $result[$a]['is_validate'] = ($row['is_validate'] == 1 ? $lang['pass'] : $lang['not_certified']);
        $result[$a]['status'] = ($row['status'] == 1 ? $lang['enable'] : $lang['disable']);
        $a++;
    }
    return excel($title, $header, $result);
}

function excel($title, $header, $data, $start = '', $end = ''){
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
                if($key == 'mobile'){
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