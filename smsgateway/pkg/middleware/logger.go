package middleware

import (
	"example.invalid/gin-gonic/gin"
	"example.invalid/sirupsen/logrus"
	"example.invalid/spf13/viper"

	"fmt"
	"os"
	"path"
	"time"
)

var (
	logger *logrus.Logger
)

// 日志记录到文件
func LoggerToFile() gin.HandlerFunc {

	pwd, err := os.Getwd()
	if err != nil {
		fmt.Println(err.Error())
	}

	//日志文件
	var fileName = path.Join(pwd, viper.GetString("log"))
	// fmt.Println("log: ", fileName)

	_, err = os.Stat(fileName)
	var src *os.File = nil
	if os.IsNotExist(err) {
		src, err = os.Create(fileName)
	} else {
		src, err = os.OpenFile(fileName, os.O_RDWR, 0)
	}

	// 写入文件
	// src, err := os.OpenFile(fileName, os.O_APPEND|os.O_WRONLY, os.ModeAppend)
	if err != nil {
		fmt.Println("err", err)
	}

	// 实例化
	logger = logrus.New()

	// 设置输出
	logger.Out = src

	// 设置日志级别
	logger.SetLevel(logrus.DebugLevel)

	return func(c *gin.Context) {
		// 开始时间
		startTime := time.Now()

		// 处理请求
		c.Next()

		// 结束时间
		endTime := time.Now()

		// 执行时间
		latencyTime := endTime.Sub(startTime)

		// 请求方式
		reqMethod := c.Request.Method

		// 请求路由
		reqUri := c.Request.RequestURI

		// 状态码
		statusCode := c.Writer.Status()

		// 请求IP
		clientIP := c.ClientIP()

		// 日志格式
		logger.WithFields(logrus.Fields{
			"status_code":  statusCode,
			"latency_time": latencyTime,
			"client_ip":    clientIP,
			"req_method":   reqMethod,
			"req_uri":      reqUri,
		}).Info()
	}
}
