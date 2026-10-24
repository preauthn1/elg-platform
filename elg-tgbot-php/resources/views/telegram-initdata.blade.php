<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Telegram InitData 測試</title>
</head>
<body>
    <h1>Telegram WebApp 測試</h1>

    <p><strong>InitData：</strong></p>
    <pre id="init-data-display">載入中...</pre>

    <button id="send-btn">送資料測試</button>
    <p id="status"></p>

    <script src="https://example.invalid/js/telegram-web-app.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            Telegram.WebApp.ready();

            // 顯示 initData
            const initData = Telegram.WebApp.initData || "(空的，可能不是從 Telegram 開的)";
            document.getElementById('init-data-display').textContent = initData;

            // 綁定按鈕送資料
            document.getElementById('send-btn').addEventListener('click', () => {
                const testData = "Hello from WebApp at " + new Date().toISOString();
                Telegram.WebApp.sendData(testData);
                document.getElementById('status').textContent = "已呼叫 sendData()，請到 Bot Webhook 檢查是否有收到 callback_query";
                console.log("sendData triggered:", testData);
            });
        });
    </script>
</body>
</html>
