package controller

import (
	"encoding/json"
	"fmt"
	"strings"

	"example.invalid/gin-gonic/gin"
)

// Controller example
type Controller struct {
}

// NewController example
func NewController() *Controller {
	return &Controller{}
}

// Message example
type Message struct {
	Message interface{} `json:"message" example:"success"`
	Code    int         `json:"code" example:"0"`
	Id      string      `json:"id,omitempty" example:"1234-5678"`
}

func toJSON(m interface{}) string {
	js, err := json.Marshal(m)
	if err != nil {
		// return "", err
		return ""
	}
	return string(js)
}

func toSliceMap(b []byte) []map[string]interface{} {
	var err error
	var data []map[string]interface{}
	err = json.Unmarshal(b, &data)
	if err != nil {
		return nil
	}
	return data
}

func toMap(b []byte) map[string]interface{} {
	var err error
	var data map[string]interface{}
	err = json.Unmarshal(b, &data)
	if err != nil {
		return nil
	}
	return data
}

func toInterface(b []byte) interface{} {
	var err error
	var data interface{}
	err = json.Unmarshal(b, &data)
	if err != nil {
		return nil
	}
	return data
}

func (c *Controller) ProcessPostUrlencoded(ctx *gin.Context) (interface{}, error) {
	err := ctx.Request.ParseForm()
	if err != nil {
		return nil, err
	}
	var data = map[string]interface{}{}
	// ctx.Request.Form 要在 ctx.Request.ParseForm() 做完才會有值
	for k, v := range ctx.Request.Form {
		// fmt.Println(v)
		if len(v) == 1 {
			data[k] = v[0]
		}
	}
	return data, nil
}

func (c *Controller) ProcessPostFormData(ctx *gin.Context) (interface{}, error) {
	form, err := ctx.MultipartForm()
	if err != nil {
		// fmt.Println("ProcessFormData error: ", err.Error())
		return nil, err
	}
	return form, nil
}

func (c *Controller) ProcessPostTEXT(ctx *gin.Context) (interface{}, error) {
	rawData, err := ctx.GetRawData()
	if err != nil {
		return nil, err
	}

	return rawData, nil
}

func (c *Controller) ProcessPostJSON(ctx *gin.Context) (interface{}, error) {

	var data map[string]interface{}

	if err := ctx.ShouldBindJSON(&data); err != nil {
		return nil, err
	}

	return data, nil
}

func (c *Controller) ProcessPost(ctx *gin.Context) (jsonBytes []byte, jsonData map[string]interface{}, err error) {
	var contentType = ctx.Request.Header.Get("Content-Type")

	fmt.Println("Content-Type: ", contentType)
	var data interface{}
	if strings.Contains(contentType, "application/x-www-form-urlencoded") {
		data, err = c.ProcessPostUrlencoded(ctx)
		if err != nil {
			return nil, nil, fmt.Errorf("ProcessPostUrlencoded Error, %s", err.Error())
		}
	} else if strings.Contains(contentType, "application/json") {
		data, err = c.ProcessPostJSON(ctx)
		if err != nil {
			return nil, nil, fmt.Errorf("ProcessPostJSON Error, %s", err.Error())
		}
	} else if strings.Contains(contentType, "text/plain") {
		data, err = c.ProcessPostTEXT(ctx)
		if err != nil {
			return nil, nil, fmt.Errorf("ProcessPostTEXT Error, %s", err.Error())
		}
	} else {
		return nil, nil, fmt.Errorf("Content-Type:%s not support", contentType)
	}

	buf, err := json.Marshal(data)
	if err != nil {
		fmt.Println(err.Error())
		return nil, nil, err
	}

	// var jsonData interface{}
	err = json.Unmarshal(buf, &jsonData)
	if err != nil {
		fmt.Println(err.Error())
		return nil, nil, err
	}
	return buf, jsonData, nil
}
