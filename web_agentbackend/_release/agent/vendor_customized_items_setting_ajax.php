<?php
require_once '../_init.php';

switch($_POST['action']) {
    case "update_sorts":
        echo updateSorts();
    break;
    case "get_item":
        echo getItem();
        break;
    case "save_item":
        echo saveItem();
        break;
    case "remove_item":
        echo removeItem();
        break;
    case "get_games":
        echo getGames();
        break;
    default:
        break;
}

function updateSorts() {
    $data = array();
    $data['group_id'] = $_POST['group_id'];
    $data['ids'] = $_POST['ids'];
    $result = curlPost('api/agent/vendor_customized_item/update_sorts', $data);
    echo json_encode($result);
}

function getItem() {
    $data = array();
    $data['id'] = $_POST['id'];
    $result = curlPost('api/agent/vendor_customized_item/get_item', $data);
    echo json_encode($result);
}

function saveItem() {
    unset($_POST['action']);
    $result = curlPost('api/agent/vendor_customized_item/save_item', $_POST);
    echo json_encode($result);
}

function removeItem() {
    unset($_POST['action']);
    $result = curlPost('api/agent/vendor_customized_item/remove_item', $_POST);
    echo json_encode($result);
}

function getGames() {
    unset($_POST['action']);
    $result = curlPost('api/agent/vendor_customized_item/get_games', $_POST);
    echo json_encode($result);
}