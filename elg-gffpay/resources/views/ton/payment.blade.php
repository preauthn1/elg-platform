<html>

<head>
    <title>Ton Pay</title>
    <link rel="icon" href="https://example.invalid/cdn-betmonsters-com-cdn-bucket/ton/ton_logo.svg">
    <script src="https://example.invalid/jquery-3.7.1.slim.min.js"
        integrity="sha256-kmHvs0B+OpCW5GVHUNjv9rOmY0IvSIRcf7zGUDTDQM8=" crossorigin="anonymous"></script>
    <script src="https://example.invalid/npm/jquery.qrcode@1.0.3/jquery.qrcode.min.js"></script>
    <script src="https://example.invalid/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous">
    </script>
    <link href="https://example.invalid/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
</head>

<body class="bg-light d-flex justify-content-center align-items-center vh-100">
    <div class="card shadow-lg p-3" style="width: 400px; border-radius: 20px;">
        <!-- Logo -->
        <div class="text-center mb-3">
            <h4 class="fw-bold text-primary">GFF-PAY</h4>
        </div>

        <!-- Network & Amount -->
        <div class="d-flex align-items-center border rounded p-2 mb-3">
            <img src="https://example.invalid/cdn-betmonsters-com-cdn-bucket/ton/ton_logo.svg" alt="TON"
                width="40" height="40" class="me-2">
            <div style="margin-left: 10px;">
                <div class="fw-bold">TON</div>
                <div class="text-muted">{{ $order->amount }} TON</div>
            </div>
        </div>

        <!-- Item Number -->
        <div class="mb-3">
            <label class="form-label fw-bold">Order Number</label>
            <input type="text" class="form-control bg-light" value="{{ $order->order_no }}" readonly>
        </div>

        @if($order->status == 1)
        <!-- Countdown -->
        <div class="text-center bg-danger text-white fw-bold p-2 rounded mb-3">
            <span id="time-remaining"></span>
        </div>

        <!-- QR Code -->
        <div class="text-center mb-3 hidden-toggle" style="display: none;">
            <div id="qrcode"></div>
        </div>

        <!-- Wallet Address -->
        <div class="mb-3 hidden-toggle" style="display: none;">
            <textarea class="form-control" id="wallet-address" rows="2" readonly style="resize: none; word-break: break-all;">{{ $order->to_address }}</textarea>
        </div>
        @elseif($order->status == 2)
        <div class="text-center bg-success text-white fw-bold p-2 rounded mb-3">
            <span>Order Success</span>
        </div>
        @elseif($order->status == 3)
        <div class="text-center bg-danger text-white fw-bold p-2 rounded mb-3">
            <span>Order Expired</span>
        </div>
        @endif

        <!-- Buttons -->
        <div class="text-center d-flex justify-content-center gap-2">
            @if($order->status == 1)
            <button class="btn btn-outline-secondary hidden-toggle" type="button" id="copy-button" data-bs-toggle="tooltip" data-bs-placement="top" title="已複製！" style="display: none;">
                <i class="bi bi-clipboard">Copy</i>
            </button>
            @endif
            @if($order->return_url)
            <a href="{{ $order->return_url }}" class="btn btn-primary">Back</a>
            @endif
        </div>
    </div>
</body>

</html>

<script>
    $(document).ready(function() {
        createQrcode();
        updateTimeRemaining();
    });

    function createQrcode() {
        const walletAddress = "{{ $order->to_address }}"; // TON 錢包地址
        const amount = "{{ $order->amount*1000000000 }}"; // 金額

        // 拼接 ton:// URI
        const tonUri = `ton://transfer/${walletAddress}?amount=${amount}`;

        // 生成 QR Code
        $("#qrcode").qrcode(tonUri);
    }

    function updateTimeRemaining(){
        const order_created_at = "{{ $order->created_at }}";
        const order_created_ms = Date.parse(order_created_at + "Z"); // UTC 轉 timestamp
        const expire_time_ms = order_created_ms + 60 * 60 * 1000;

        updateTimeRemainingInterval(expire_time_ms);
        setInterval(function() {
            updateTimeRemainingInterval(expire_time_ms);
        }, 1000);
    }

    function updateTimeRemainingInterval(expire_time_ms) {
        const current_time_ms = Date.now();
        let time_diff = expire_time_ms - current_time_ms;

        if (time_diff <= 0) {
            $("#time-remaining").text("Order Expired");
            $(".hidden-toggle").hide();
            return;
        }
        $(".hidden-toggle").show();

        const minutes = Math.floor(time_diff / 60000);
        const seconds = Math.floor((time_diff % 60000) / 1000);

        // 補零
        const mm = String(minutes).padStart(2, "0");
        const ss = String(seconds).padStart(2, "0");

        $("#time-remaining").text(`${mm}:${ss}`);
    }

    // 啟用 Bootstrap Tooltip
    const tooltipTrigger = document.getElementById('copy-button');
    const tooltip = new bootstrap.Tooltip(tooltipTrigger, {
        trigger: 'manual' // 手動觸發
    });    

    // 複製到剪貼簿
    $("#copy-button").click(function() {
        const text = document.getElementById("wallet-address").value;

        const tempInput = document.createElement("input");
        tempInput.value = text;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand("copy");
        document.body.removeChild(tempInput);

        // 顯示 tooltip
        tooltip.show();

        // 1.5 秒後自動隱藏
        setTimeout(() => {
            tooltip.hide();
        }, 1500);
    });
</script>
