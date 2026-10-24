package datetime

import (
	"fmt"
	"regexp"
	"time"
)

var loc, _ = time.LoadLocation("UTC")

type dateTimePattern struct {
	match  string
	format string
}

var patternList = []dateTimePattern{
	{`^[0-9]{4}-[0-9]{2}-[0-9]{2}$`, "2006-01-02"},
	{`^[0-9]{4}-[0-9]{2}-[0-9]{2}@[0-9]{2}$`, "2006-01-02@15"},
	{`^[0-9]{4}-[0-9]{2}-[0-9]{2}@[0-9]{2}:[0-9]{2}:[0-9]{2}$`, "2006-01-02@15:04:05"},
	{`^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$`, "2006-01-02 15:04:05"},
}

// DateTimeParse 解析日期字串
func DateTimeParse(s string) (time.Time, error) {
	if format, ok := detechFormat(s); ok {
		return time.ParseInLocation(format, s, loc)
	}
	return time.Now(), fmt.Errorf("error with no format, %s", s)
}

func detechFormat(s string) (string, bool) {
	for _, p := range patternList {
		if match, _ := regexp.MatchString(p.match, s); match {
			return p.format, true
		}
	}
	return "", false
}

func SetFixedZone(timezone int) {
	loc = time.FixedZone(fmt.Sprintf("UTC%+d", timezone), timezone*60*60)
}

func RangeDate(start, end time.Time) func() time.Time {
	y, m, d := start.Date()
	start = time.Date(y, m, d, 0, 0, 0, 0, start.Location())
	y, m, d = end.Date()
	end = time.Date(y, m, d, 0, 0, 0, 0, end.Location())

	return func() time.Time {
		if start.After(end) {
			return time.Time{}
		}
		date := start
		start = start.AddDate(0, 0, 1)
		return date
	}
}
