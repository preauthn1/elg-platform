package httputil

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io/ioutil"
	"net/http"
	"net/url"
	"strings"
)

func PostUrlByJson(contentType string, jsonBytes []byte, path string) (response []byte, err error) {
	var raw = bytes.NewBuffer(jsonBytes)

	req, err := http.NewRequest("POST", path, raw)
	req.Header.Set("Content-Type", contentType)
	if err != nil {
		return nil, fmt.Errorf("new request error: %s", err.Error())
	}
	// fmt.Println(req.Header.Get("Authorization"))

	var client = &http.Client{}
	res, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("send request error: %s", err.Error())
	}

	defer res.Body.Close()

	body, err := ioutil.ReadAll(res.Body)
	if err != nil {
		return nil, fmt.Errorf("ioutil.ReadAll error: %s", err.Error())
	}

	return body, nil
}

func PostUrlByJsonSetAuth(contentType string, jsonBytes []byte, path string, user string, password string) (response []byte, err error) {
	var raw = bytes.NewBuffer(jsonBytes)

	req, err := http.NewRequest("POST", path, raw)
	req.SetBasicAuth(user, password)
	req.Header.Set("Content-Type", contentType)
	if err != nil {
		return nil, fmt.Errorf("new request error: %s", err.Error())
	}
	// fmt.Println(req.Header.Get("Authorization"))

	var client = &http.Client{}
	res, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("send request error: %s", err.Error())
	}

	defer res.Body.Close()

	body, err := ioutil.ReadAll(res.Body)
	if err != nil {
		return nil, fmt.Errorf("ioutil.ReadAll error: %s", err.Error())
	}

	return body, nil
}

func PostUrlByForm(contentType string, jsonBytes []byte, path string) (response []byte, err error) {

	var jsonMap = map[string]string{}
	err = json.Unmarshal(jsonBytes, &jsonMap)
	if err != nil {
		fmt.Println(err.Error())
	}

	data := url.Values{}
	for k, v := range jsonMap {
		data.Add(k, v)
	}

	req, err := http.NewRequest("POST", path, strings.NewReader(data.Encode()))

	req.Header.Set("Content-Type", contentType)
	if err != nil {
		return nil, fmt.Errorf("new request error: %s", err.Error())
	}
	// fmt.Println(req.Header.Get("Authorization"))

	var client = &http.Client{}
	res, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("send request error: %s", err.Error())
	}

	defer res.Body.Close()

	body, err := ioutil.ReadAll(res.Body)
	if err != nil {
		return nil, fmt.Errorf("ioutil.ReadAll error: %s", err.Error())
	}

	return body, nil
}

func PostUrlByFormSetAuth(contentType string, jsonBytes []byte, path string, user string, password string) (response []byte, err error) {

	var jsonMap = map[string]string{}
	err = json.Unmarshal(jsonBytes, &jsonMap)
	if err != nil {
		fmt.Println(err.Error())
	}

	data := url.Values{}
	for k, v := range jsonMap {
		data.Add(k, v)
	}

	req, err := http.NewRequest("POST", path, strings.NewReader(data.Encode()))

	req.SetBasicAuth(user, password)
	req.Header.Set("Content-Type", contentType)
	if err != nil {
		return nil, fmt.Errorf("new request error: %s", err.Error())
	}
	// fmt.Println(req.Header.Get("Authorization"))

	var client = &http.Client{}
	res, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("send request error: %s", err.Error())
	}

	defer res.Body.Close()

	fmt.Println(res.StatusCode, res.Status)

	body, err := ioutil.ReadAll(res.Body)
	if err != nil {
		return nil, fmt.Errorf("ioutil.ReadAll error: %s", err.Error())
	}

	return body, nil
}
