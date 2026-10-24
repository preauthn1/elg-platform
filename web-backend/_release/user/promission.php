<?php
require_once '../_init.php';
$data = array();
$data['user_id'] = $_POST['id'];
$result = curlPost('api/userMenu/list', $data);
if ($result['code'] != 1000) {
    if ($result['code'] > 9997) {
        header("location:" . BASE_URL . "login.php");
    } else {
        header('Location:' . $_SERVER['HTTP_REFERER']);
    }
    exit();
}
var_dump($result);
exit();
?>
<table class="table table-striped">
    <thead>
        <tr>
            <th>功能名稱</th>
            <th>查詢</th>
            <th>新增</th>
            <th>修改</th>
            <th>刪除</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>系統管理 > Banner 管理</td>
            <td>
                <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                    <input type="checkbox" name="toggle1" id="toggle1" checked>
                    <label for="toggle1"></label>
                </div>
            </td>
            <td>
                <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                    <input type="checkbox" name="toggle1" id="toggle1" checked>
                    <label for="toggle1"></label>
                </div>
            </td>
            <td>
                <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                    <input type="checkbox" name="toggle1" id="toggle1" checked>
                    <label for="toggle1"></label>
                </div>
            </td>
            <td>
                <div class="toggle-checkbox toggle-success checkbox-inline toggle-sm mrg-top-10">
                    <input type="checkbox" name="toggle1" id="toggle1" checked>
                    <label for="toggle1"></label>
                </div>
            </td>
        </tr>
    </tbody>
</table>
