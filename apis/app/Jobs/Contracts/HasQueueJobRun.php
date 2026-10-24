<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

use App\Enums\QueueJobKeyEnum;

/**
 * 契約層：宣告此 queue job 的執行以 queue_job_runs 追蹤生命週期。
 *
 * 派發端（排程 service、後台 AdminOperationService、job 自我續鏈）於入列前依本契約
 * 提供的鍵值呼叫 QueueJobRun::createPending() 建列，並以 setQueueJobRunId() 把列 id
 * 交給 job；job 收尾（handle 尾端／failed hook）以 finishRun() update 同列。
 *
 * 鍵值方法在「派發當下」於派發端執行——bucket_time 等時間值以派發時點為準。
 */
interface HasQueueJobRun
{
    public function queueJobRunKey(): QueueJobKeyEnum;

    public function queueJobRunWorkKey(): string;

    /** 本次執行的時間格起點（Y-m-d H:i:s，UTC；語意由各 job 定義） */
    public function queueJobRunBucketTime(): string;

    public function setQueueJobRunId(int $id): void;
}
