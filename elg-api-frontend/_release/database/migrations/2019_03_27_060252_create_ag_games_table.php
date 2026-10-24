<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAgGamesTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ag_games', function (Blueprint $table) {
            $table->increments('id')->comment('流水號');
            $table->string('game_type')->comment('遊戲代碼');
            $table->string('en_name')->comment('英文名稱');
            $table->string('zh_tw_name')->comment('繁中名稱');
            $table->string('zh_cn_name')->comment('簡中名稱');
            $table->tinyInteger('status')->comment('狀態：0停用，1啟用');
            $table->timestamp('created_at')
                ->useCurrent()
                ->comment('建立日期');
            $table->timestamp('updated_at')
                ->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'))
                ->comment('更新日期');
            $table->integer('admin')->comment('更新者ID');
        });

        // $games = array(
        // array(
        // "水果拉霸",
        // "水果拉霸",
        // "Fruit slots",
        // "101"
        // ),
        // array(
        // "杰克高手",
        // "杰克高手",
        // "Jacks or Better",
        // "102"
        // ),
        // array(
        // "太空漫游",
        // "太空漫遊",
        // "Space Odyssey",
        // "108"
        // ),
        // array(
        // "复古花园",
        // "復古花園",
        // "Garden Vintage",
        // "109"
        // ),
        // array(
        // "日本武士",
        // "日本武士",
        // "Samurai slots",
        // "113"
        // ),
        // array(
        // "象棋老虎机",
        // "象棋老虎機",
        // "Chinese Chess slots",
        // "114"
        // ),
        // array(
        // "麻将老虎机",
        // "麻雀老虎機",
        // "Mahjong slots",
        // "115"
        // ),
        // array(
        // "开心农场",
        // "開心農場",
        // "Happy Farm",
        // "117"
        // ),
        // array(
        // "夏日营地",
        // "夏日營地",
        // "Summer Campsite",
        // "118"
        // ),
        // array(
        // "武财神",
        // "武財神",
        // "God of Wu Fortune",
        // "135"
        // ),
        // array(
        // "灵猴献瑞",
        // "靈猴獻瑞",
        // "Year of the Monkey",
        // "139"
        // ),
        // array(
        // "糖果碰碰乐",
        // "糖果碰碰樂",
        // "Candy Quest",
        // "144"
        // ),
        // array(
        // "冰河世界",
        // "冰河世界",
        // "Ice Crush",
        // "145"
        // ),
        // array(
        // "水果拉霸2",
        // "水果拉霸2",
        // "Fruit Slot 2",
        // "146"
        // ),
        // array(
        // "上海百乐门",
        // "上海百樂門",
        // "Shanghai Bund",
        // "149"
        // ),
        // array(
        // "猛龙传奇",
        // "猛龍傳奇",
        // "Legend of the Dragon",
        // "156"
        // ),
        // array(
        // "神奇宝石",
        // "神奇寶石",
        // "Mystic Gems",
        // "158"
        // ),
        // array(
        // "金龙珠",
        // "金龍珠",
        // "The Dragon Pearl Gold",
        // "160"
        // ),
        // array(
        // "XIN哥来了",
        // "XIN哥來了",
        // "Mr XIN",
        // "161"
        // ),
        // array(
        // "王者传说",
        // "王者傳説",
        // "King of Glory",
        // "162"
        // ),
        // array(
        // "龙珠",
        // "龍珠",
        // "Dragons Pearl",
        // "200"
        // ),
        // array(
        // "幸运8",
        // "幸運8",
        // "lucky 8",
        // "201"
        // ),
        // array(
        // "闪亮女郎",
        // "閃亮女郎",
        // "Bling Bling",
        // "202"
        // ),
        // array(
        // "金鱼",
        // "金魚",
        // "Goldfish",
        // "203"
        // ),
        // array(
        // "中国新年",
        // "中國新年",
        // "Chinese New Year",
        // "204"
        // ),
        // array(
        // "海盗王",
        // "海盜王",
        // "Pirates",
        // "205"
        // ),
        // array(
        // "鲜果狂热",
        // "鮮果狂熱",
        // "Fruitmania",
        // "206"
        // ),
        // array(
        // "小熊猫",
        // "小熊貓",
        // "Red Panda",
        // "207"
        // ),
        // array(
        // "大豪客",
        // "大豪客",
        // "High Roller",
        // "208"
        // ),
        // array(
        // "龙舟竞渡",
        // "龍舟競渡",
        // "Dragon Boat Festival",
        // "209"
        // ),
        // array(
        // "中秋佳节",
        // "中秋佳節",
        // "Moon Festival",
        // "210"
        // ),
        // array(
        // "韩风劲舞",
        // "韓風劲舞",
        // "K-Pop",
        // "211"
        // ),
        // array(
        // "美女大格斗",
        // "美女大格鬥",
        // "Fantasies",
        // "212"
        // ),
        // array(
        // "龙凤呈祥",
        // "龍鳳呈祥",
        // "Phoenix and Dragon",
        // "213"
        // ),
        // array(
        // "黄金对垒",
        // "黃金對壘",
        // "Kick Off",
        // "215"
        // ),
        // array(
        // "街头烈战",
        // "街頭烈戰",
        // "Street Battle",
        // "221"
        // ),
        // array(
        // "金拉霸",
        // "金拉霸",
        // "Gold Class",
        // "802"
        // ),
        // array(
        // "百搭777",
        // "百搭777",
        // "Wild 777",
        // "804"
        // ),
        // array(
        // "赛亚烈战",
        // "賽亞烈戰",
        // "Saiyan Battle Z",
        // "DTGDTA0"
        // ),
        // array(
        // "五行世界",
        // "五行世界",
        // "Five Elements",
        // "DTGDTA8"
        // ),
        // array(
        // "梦幻森林",
        // "夢幻森林",
        // "Fantasy Forest",
        // "DTGDTAB"
        // ),
        // array(
        // "财神到",
        // "財神到",
        // "Wealthy God Arriving",
        // "DTGDTAF"
        // ),
        // array(
        // "新年到",
        // "新年到",
        // "Happy New Year",
        // "DTGDTAG"
        // ),
        // array(
        // "封神榜",
        // "封神榜",
        // "Investiture of Gods",
        // "DTGDTAM"
        // ),
        // array(
        // "龙凤呈祥",
        // "龍鳳呈祥",
        // "DragonPhoenix Prosper",
        // "DTGDTAQ"
        // ),
        // array(
        // "英雄荣耀",
        // "英雄榮耀",
        // "Glory of Heroes",
        // "DTGDTAR"
        // ),
        // array(
        // "福禄寿",
        // "福祿壽",
        // "FULUSHOU",
        // "DTGDTAT"
        // ),
        // array(
        // "摇滚之夜",
        // "搖滾之夜",
        // "Rocknight",
        // "DTGDTAZ"
        // ),
        // array(
        // "快乐农庄",
        // "快樂農莊",
        // "Happy Farm",
        // "DTGDTB1"
        // ),
        // array(
        // "龙虎",
        // "龍虎",
        // "DragonTiger",
        // "EP02"
        // ),
        // array(
        // "骰宝",
        // "骰寶",
        // "Sicbo",
        // "EP03"
        // ),
        // array(
        // "欧洲轮盘（桌面版）",
        // "歐洲輪盤（桌面版）",
        // "Roulette Deluxe (desktop)",
        // "TAITA1L"
        // ),
        // array(
        // "欧洲轮盘 低额投注（桌面版）",
        // "歐洲輪盤 低額投注（桌面版）",
        // "Roulette Deluxe Low Limits (desktop)",
        // "TAITA1M"
        // ),
        // array(
        // "欧洲轮盘（移动版）",
        // "歐洲輪盤（移動版）",
        // "Roulette Deluxe (mobile)",
        // "TAITA1O"
        // ),
        // array(
        // "欧洲轮盘 低额投注（移动版）",
        // "歐洲輪盤 低額投注（移動版）",
        // "Roulette Deluxe Low Limits (mobile)",
        // "TAITA1P"
        // ),
        // array(
        // "王牌多手21点",
        // "王牌多手21點",
        // "Multihand Blackjack Deluxe",
        // "TAITA1Q"
        // ),
        // array(
        // "聚宝龙",
        // "聚寶龍",
        // "Fortune Dragon",
        // "TAITA1U"
        // ),
        // array(
        // "捕猎季节(高清)",
        // "捕獵季節(高清)",
        // "Hunting Season HD",
        // "TAITA1X"
        // ),
        // array(
        // "钻石女王",
        // "鑽石女王",
        // "Diamond Queen",
        // "WHGWA01"
        // ),
        // array(
        // "阿里巴巴",
        // "阿里巴巴",
        // "Alibaba",
        // "WHGWH01"
        // ),
        // array(
        // "冠军足球",
        // "冠軍足球",
        // "Real Champions",
        // "WHGWH03"
        // ),
        // array(
        // "穆夏女神",
        // "穆夏女神",
        // "Mucha Goddesses",
        // "WHGWH04"
        // ),
        // array(
        // "亚瑟王",
        // "亞瑟王",
        // "King Arthur",
        // "WHGWH06"
        // ),
        // array(
        // "爱丽丝大冒险",
        // "愛麗絲大冒險",
        // "Alice's Adventures",
        // "WHGWH10"
        // ),
        // array(
        // "战火风云",
        // "戰火風雲",
        // "The Storm",
        // "WHGWH11"
        // ),
        // array(
        // "嫦娥奔月",
        // "嫦娥奔月",
        // "The Moon Lady",
        // "WHGWH17"
        // )
        // );
        // foreach ($games as $row) {
        // DB::table('ag_games')->insert(array(
        // 'game_type' => $row[3],
        // 'en_name' => $row[2],
        // 'zh_tw_name' => $row[1],
        // 'zh_cn_name' => $row[0],
        // 'status' => 1,
        // 'admin' => 1
        // ));
        // }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ag_games');
    }
}
