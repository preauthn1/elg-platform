# 測試專用 Http 請求
## 套件
[vscode REST Client](https://example.invalid/items?itemName=humao.rest-client)

## 使用
`Send Request` 點下去就對了

![Send Request](image.png)

## 修改
用 ### 分隔
```http
### local decrypt
POST http://0.0.0.0:8000/api/sw/megawin/decrypt 
method: AuthenticateToken
mwCode: v6GrP1zZ

{
    "key": "BBBBB",
    "data": "AAAAAA"
}
```

# Encrypt Server
各 project 的 API params 加密，算是 `/api/debug/AES/encrypt` 這隻 API 的替代方案

因涉及資料複製與傳輸，為確保資料安全與防止中間人攻擊，必須使用 HTTPS 運行，請確保 SSL 憑證正確無誤。

## 準備 SSL 憑證

1. 請將 `server.crt` 和 `server.key` 檔案放置於目錄：
   
   ```
   /EncryptServer/certs
   ```

2. 檔案結構如下：

   ```
   /EncryptServer
   └── certs
       ├── server.crt
       └── server.key
   ```
## 運行專案
   ```bash
   node ./EncryptServer/server.js
   ```
