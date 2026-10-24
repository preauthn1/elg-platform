package uid

import (
	"errors"
	"fmt"
	"sync"
	"time"
)

//參考 SnowFlake 來製作一個唯一ID生成
//https://example.invalid/foreverling/article/details/80753007

const (
	NodeIdBits   = uint(2) //节点 所占位置
	sequenceBits = uint(2) //自增ID 所占用位置

	/*
	 * 格式
	 * UnixTime | 2 节点  | 2 （毫秒内）自增ID
	 * 1547863118  | 00  |  00
	 *
	 */
	maxNodeId = -1 ^ (-1 << NodeIdBits) //节点 ID 最大范围

	nodeIdShift        = sequenceBits //左移次数
	timestampLeftShift = sequenceBits + NodeIdBits
	sequenceMask       = -1 ^ (-1 << sequenceBits)
	maxNextIdsNum      = 10 //单次获取ID的最大数量
)

type IdWorker struct {
	timeformat    string //每個毫秒會產生一次該毫秒的timeformat
	sequence      int64  //序号
	lastTimestamp int64  //最后时间戳
	nodeId        int64  //节点ID
	mutex         sync.Mutex
}

var UidWorker *IdWorker

func init() {
	var err error
	fmt.Println("init ordergentool, create default worker")
	UidWorker, err = NewIdWorker(0)
	if err != nil {
		fmt.Println("ordergentool Init default worker fail...err:", err)
	}
}

// NewIdWorker new a snowflake id gg object.
func NewIdWorker(NodeId int64) (*IdWorker, error) {
	idWorker := &IdWorker{}
	if NodeId > maxNodeId || NodeId < 0 {
		fmt.Println(fmt.Sprintf("NodeId Id can't be greater than %d or less than 0", maxNodeId))
		return nil, errors.New(fmt.Sprintf("NodeId Id: %d error", NodeId))
	}
	idWorker.nodeId = NodeId
	idWorker.lastTimestamp = -1
	idWorker.sequence = 0
	idWorker.mutex = sync.Mutex{}
	fmt.Println(fmt.Sprintf("worker starting. timestamp left shift %d, worker id bits %d, sequence bits %d, workerid %d", timestampLeftShift, NodeIdBits, sequenceBits, NodeId))
	return idWorker, nil
}

// timeGen generate a unix time (in sec)
func timeGen() int64 {
	return time.Now().Unix()
}

func getTimeString() string {
	return fmt.Sprintf("%d", timeGen())
}

// tilNextMillis spin wait till next millisecond.
func tilNextMillis(lastTimestamp int64) int64 {
	timestamp := timeGen()
	for timestamp <= lastTimestamp {
		timestamp = timeGen()
	}
	return timestamp
}

// NextId get a snowflake id.
func (id *IdWorker) NextId() (string, error) {
	id.mutex.Lock()
	defer id.mutex.Unlock()
	return id.nextid()
}

// NextIds get snowflake ids.
func (id *IdWorker) NextIds(num int) ([]string, error) {
	if num > maxNextIdsNum || num < 0 {
		fmt.Println(fmt.Sprintf("NextIds num can't be greater than %d or less than 0", maxNextIdsNum))
		return nil, errors.New(fmt.Sprintf("NextIds num: %d error", num))
	}
	ids := make([]string, num)
	id.mutex.Lock()
	defer id.mutex.Unlock()
	for i := 0; i < num; i++ {
		ids[i], _ = id.nextid()
	}
	return ids, nil
}

func (id *IdWorker) nextid() (string, error) {
	timestamp := timeGen()
	if timestamp < id.lastTimestamp {
		fmt.Println(fmt.Sprintf("clock is moving backwards.  Rejecting requests until %d.", id.lastTimestamp))
		return "", errors.New(fmt.Sprintf("Clock moved backwards.  Refusing to generate id for %d milliseconds", id.lastTimestamp-timestamp))
	}
	if id.lastTimestamp == timestamp {
		id.sequence = (id.sequence + 1) & sequenceMask
		if id.sequence == 0 {
			timestamp = tilNextMillis(id.lastTimestamp)
			id.timeformat = getTimeString()
		}
	} else {
		id.sequence = 0
		id.timeformat = getTimeString()
	}
	id.lastTimestamp = timestamp
	return fmt.Sprintf("%s%02d%02d", id.timeformat, id.nodeId, id.sequence), nil
}
