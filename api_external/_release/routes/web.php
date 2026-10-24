<?php

/*
 * |--------------------------------------------------------------------------
 * | Web Routes
 * |--------------------------------------------------------------------------
 * |
 * | Here is where you can register web routes for your application. These
 * | routes are loaded by the RouteServiceProvider within a group which
 * | contains the "web" middleware group. Now create something great!
 * |
 */

use App\Helpers\Common;

Route::get('/', function () {
    Common::log(array('api'=>'ext'), 'actionApiExternal');
    return view('welcome');
});
/*
Route::get('/test', function() {
    $order_id = 973;
    $lock = new \target\support\Models\OrderNamedLock($order_id);
    if ($lock->getStatus()) {
        $order = \target\support\Models\Order::find($order_id);
        if($order->status == 0) {
            $order->status=1;
            $order->save();
        }
        $service = new \target\support\Services\CashFlowService();
        $service->updateMemberBalance($order);
    }
    $lock->unLock();
});
*/
//Route::get('/tginfo', function(){
//    \App\Helpers\TelegramHelper::sendMessage(
//        sprintf("單號 [%s] USDT 打款數目比訂單數目少, 更新 amount %.2f to %.2f", '202101215585879054', 273.0000, 259.05),
//        '',
//        'info'
//    );
//});