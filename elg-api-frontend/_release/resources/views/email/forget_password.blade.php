<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <title>{{$title}}</title>
  </head>
  <body>
    <div class="ALL">
      <div class="Content">
        <div class="account">用戶帳號：{{$account}}</div>
        <div class="banner"><img src="./img/eventPic.png" width="100%" /></div>
        <div class="FontAll">
          <div class="nameTitle">親愛的{{$name}}</div>

          <div class="contentFont">忘記密碼需要重設?請點擊下面網址<br />
            {{$url}}
          </div>
            <a href="{{$url}}">
              <div class="buttonPic">
                <div class="buttonfont">重設密碼</div>
              </div>
            </a>
          <div class="team">{{$title}}</div>
        </div>
        <div class="footer">© 2019 {{$title}} 版權所有</div>
      </div>
    </div>
  </body>
</html>
<style>
  .ALL {
    width: 100%;
    height: 100vh;
  }
  .Content {
    width: 80%;
    height: 100%;
    margin: auto;
    font-size: 18px;
  }
  .account {
    width: 100%;
    text-align: right;
    margin: 20px auto;
    font-size: 14px;
  }
  .banner {
    width: 100%;
  }
  .FontAll {
    width: 98%;
    margin: 20px auto;
  }
  .nameTitle {
    margin: 10px auto;
    font-weight: bold;
  }

  .contentFont {
    font-size: 14px;
    line-height: 35px;
  }

  .buttonPic {
    position: relative;
    width: 140px;
    margin: 20px auto;
    height: 38px;
    line-height: 39px;
    background-color: #0044BB;
    background-repeat: no-repeat;
    background-size: 100%;
    text-align: center;
    cursor: pointer;
    outline: none;
    border-radius: 5px;
    box-shadow: 0 7px #0b316d;
  }
  .buttonfont {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    font-size: 15px;
    color: #fff;
    font-weight: bold;
  }
  .buttonPic:active {
    background-color: 	#003377;
    box-shadow: 0 4px #666;
    transform: translateY(4px);
  }
  .team {
    width: 100%;
    text-align: center;
    font-weight: bold;
    margin: 35px auto;
  }
  .footer {
    width: 100%;
    text-align: center;
    font-size: 13px;
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
  }
</style>
