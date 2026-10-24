GOOS=windows GOARCH=amd64 CGO_ENABLED=0 go build -o bin/getBetRecords.exe main.go
GOOS=linux GOARCH=amd64 CGO_ENABLED=0 go build -o bin/getBetRecords main.go
